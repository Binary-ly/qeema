<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\IngestionBatch;
use App\Models\Source;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use App\Support\Ingestion\ColumnMapping;
use App\Support\Ingestion\PartnerFileImporter;
use App\Support\Ingestion\SpreadsheetReader;

/*
|--------------------------------------------------------------------------
| Partner file ingestion
|--------------------------------------------------------------------------
|
| The requirement this exists to satisfy: a malformed partner file must produce
| useful per-row errors, never a 500 and never a wholesale rejection. A partner
| whose 900 good rows are thrown away because 100 were wrong stops sending files.
|
*/

beforeEach(function () {
    (new CountryConfigImporter)->import(
        (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
    );

    $this->country = Country::query()->where('code', 'LY')->firstOrFail();
    $this->source = Source::query()
        ->where('country_id', $this->country->id)
        ->where('type', Source::TYPE_PARTNER_UPLOAD)
        ->firstOrFail();
});

/** Write a CSV to a temp file and return its path. */
function csvFile(string $contents, string $extension = 'csv'): string
{
    $path = sys_get_temp_dir().'/qeema-partner-'.bin2hex(random_bytes(6)).'.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

function defaultMapping(): ColumnMapping
{
    return ColumnMapping::fromArray([
        'item' => 'Product',
        'price' => 'Price',
        'location' => 'Market',
        'unit' => 'Unit',
        'observed_at' => 'Date',
    ]);
}

describe('reading files', function () {
    it('reads a comma-separated file', function () {
        $path = csvFile("Product,Price,Market\nRice,6.50,Tripoli\nFlour,4.20,Benghazi\n");

        $rows = iterator_to_array((new SpreadsheetReader)->rows($path));

        expect($rows)->toHaveCount(2)
            ->and($rows[2]['Product'])->toBe('Rice');
    });

    it('reads a semicolon-separated file, which is what Excel produces in much of the world', function () {
        // Read as comma-separated this yields one column and a wall of
        // meaningless errors, so the delimiter is sniffed rather than assumed.
        $path = csvFile("Product;Price;Market\nRice;6,50;Tripoli\n");

        $rows = iterator_to_array((new SpreadsheetReader)->rows($path));

        expect($rows[2]['Product'])->toBe('Rice')
            ->and($rows[2]['Price'])->toBe('6,50');
    });

    it('numbers rows as a human would see them in a spreadsheet', function () {
        // An error report pointing at "row 2" must mean the row the partner can
        // open and fix, which includes the header.
        $path = csvFile("Product,Price,Market\nRice,6.50,Tripoli\n");

        expect(array_key_first(iterator_to_array((new SpreadsheetReader)->rows($path))))->toBe(2);
    });

    it('skips entirely blank rows', function () {
        $path = csvFile("Product,Price,Market\nRice,6.50,Tripoli\n,,\n\nFlour,4.20,Sabha\n");

        expect(iterator_to_array((new SpreadsheetReader)->rows($path)))->toHaveCount(2);
    });

    it('tolerates rows shorter than the header', function () {
        $path = csvFile("Product,Price,Market,Unit\nRice,6.50,Tripoli\n");

        $rows = iterator_to_array((new SpreadsheetReader)->rows($path));

        expect($rows[2]['Unit'])->toBeNull();
    });

    it('rejects an unsupported file type with a readable message', function () {
        $path = csvFile('nonsense', 'pdf');

        expect(fn () => iterator_to_array((new SpreadsheetReader)->rows($path)))
            ->toThrow(RuntimeException::class, 'Unsupported file type');
    });
});

describe('column mapping', function () {
    it('guesses common English headers', function () {
        $mapping = ColumnMapping::guess(['Product', 'Unit Price', 'Market', 'Date']);

        expect($mapping->column('item'))->toBe('Product')
            ->and($mapping->column('location'))->toBe('Market')
            ->and($mapping->column('observed_at'))->toBe('Date');
    });

    it('guesses Arabic headers', function () {
        // Partners send files in their own language; demanding English headers
        // is how a data-sharing arrangement quietly dies.
        $mapping = ColumnMapping::guess(['السلعة', 'السعر', 'المدينة']);

        expect($mapping->column('item'))->toBe('السلعة')
            ->and($mapping->column('price'))->toBe('السعر')
            ->and($mapping->column('location'))->toBe('المدينة');
    });

    it('knows when required fields are unmapped', function () {
        $mapping = ColumnMapping::guess(['Something', 'Irrelevant']);

        expect($mapping->isComplete())->toBeFalse()
            ->and($mapping->missingRequired())->toContain('item', 'price', 'location');
    });

    it('offers a mapping and a sample without importing', function () {
        $path = csvFile("Product,Price,Market\nRice,6.50,Tripoli\nFlour,4.20,Sabha\n");

        $inspection = (new PartnerFileImporter)->inspect($path);

        expect($inspection['headers'])->toBe(['Product', 'Price', 'Market'])
            ->and($inspection['mapping']->isComplete())->toBeTrue()
            ->and($inspection['sample'])->toHaveCount(2)
            ->and(Submission::query()->count())->toBe(0);
    });
});

describe('importing a clean file', function () {
    it('creates a submission per row', function () {
        $path = csvFile("Product,Price,Market,Unit,Date\nRice,6.50,Tripoli,kg,2026-03-01\nFlour,4.20,Sabha,kg,2026-03-01\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect($batch->status)->toBe(IngestionBatch::STATUS_COMPLETED)
            ->and($batch->row_count)->toBe(2)
            ->and($batch->accepted_count)->toBe(2)
            ->and($batch->rejected_count)->toBe(0)
            ->and(Submission::query()->count())->toBe(2);
    });

    it('preserves the partner text verbatim', function () {
        $path = csvFile("Product,Price,Market\nحليب أطفال ٤٠٠ غرام,32.5,Tripoli\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect(Submission::query()->firstOrFail()->raw_text)->toBe('حليب أطفال ٤٠٠ غرام');
    });

    it('matches locations by local-language name', function () {
        $path = csvFile("Product,Price,Market\nRice,6.50,طرابلس\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect($batch->accepted_count)->toBe(1);
    });

    it('records the observation date from the file', function () {
        $path = csvFile("Product,Price,Market,Unit,Date\nRice,6.50,Tripoli,kg,2026-03-01\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect(Submission::query()->firstOrFail()->observed_at->toDateString())->toBe('2026-03-01');
    });

    it('links every submission back to its batch and row', function () {
        $path = csvFile("Product,Price,Market\nRice,6.50,Tripoli\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());
        $submission = Submission::query()->firstOrFail();

        expect($submission->ingestion_batch_id)->toBe($batch->id)
            ->and($submission->device_metadata['row'])->toBe(2);
    });
});

describe('number and date formats partners actually use', function () {
    it('reads a comma decimal mark', function () {
        $path = csvFile("Product;Price;Market\nRice;6,50;Tripoli\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect((float) Submission::query()->firstOrFail()->raw_price)->toBe(6.5);
    });

    /*
    | "1,250" used to be read here as one thousand two hundred and fifty, and
    | that assertion lived in this file as `it('reads a thousands separator')`.
    |
    | It is not safe in a three-decimal currency. The dinar has three minor
    | units, so Libyan sellers write twenty dinars as "20,000" — Dat Essawary
    | Pharmacy's whole catalogue is rendered that way, confirmed against the
    | site's own "1 - 200" price filter, and a second independent sweep of
    | Libyan sellers found the same convention. Under the old rule every one of
    | those prices arrived a thousand times too high, which is exactly the defect
    | that once put a 13,000 LYD basket on the dashboard.
    |
    | Reading it the other way is no better: it turns a genuine 1,250 into 1.25,
    | the same error pointing the other way. Nothing in the string settles it, so
    | the row goes back to the partner, who knows what they meant.
    */
    it('refuses a separator that could be either mark, rather than guessing', function () {
        $path = csvFile("Product,Price,Market\nGas cylinder,\"1,250\",Tripoli\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect(Submission::query()->count())->toBe(0)
            ->and($batch->rejected_count)->toBe(1);
    });

    it('tells the partner both readings so they can resolve it', function () {
        $path = csvFile("Product,Price,Market\nPanadol,\"20,000\",Tripoli\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());
        $errors = $batch->errorRows();

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['column'])->toBe('Price')
            ->and($errors[0]['message'])->toContain('20000')
            ->and($errors[0]['message'])->toContain('20.000')
            ->and($errors[0]['message'])->toContain('LYD');
    });

    it('still reads a thousands separator where the currency has two decimals', function () {
        // The rule is a property of the currency, not of this codebase. The
        // bolivar has two minor units, so "1,250" there is unambiguous and must
        // keep working — otherwise a fix for Libya quietly breaks Venezuela.
        (new CountryConfigImporter)->import(
            (new CountryConfigLoader)->load(base_path('../countries/ve.yaml'))
        );

        $venezuela = Country::query()->where('code', 'VE')->firstOrFail();
        $source = Source::query()
            ->where('country_id', $venezuela->id)
            ->where('type', Source::TYPE_PARTNER_UPLOAD)
            ->firstOrFail();

        $path = csvFile("Product,Price,Market\nHarina,\"1,250\",Caracas\n");

        (new PartnerFileImporter)->import($source, $path, defaultMapping());

        expect((float) Submission::query()->firstOrFail()->raw_price)->toBe(1250.0);
    });

    it('reads a grouped number that carries more than one comma', function () {
        // Two commas cannot be decimal marks, so nothing is ambiguous here even
        // in a three-decimal currency.
        $path = csvFile("Product,Price,Market\nBakery flour,\"1,234,500\",Tripoli\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect((float) Submission::query()->firstOrFail()->raw_price)->toBe(1234500.0);
    });

    it('reads both separators together', function () {
        $path = csvFile("Product,Price,Market\nGas,\"1,250.75\",Tripoli\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect((float) Submission::query()->firstOrFail()->raw_price)->toBe(1250.75);
    });

    it('reads Arabic-Indic digits', function () {
        $path = csvFile("Product,Price,Market\nRice,٦.٥٠,Tripoli\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect((float) Submission::query()->firstOrFail()->raw_price)->toBe(6.5);
    });

    it('ignores a currency symbol beside the number', function () {
        $path = csvFile("Product,Price,Market\nRice,LYD 6.50,Tripoli\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect((float) Submission::query()->firstOrFail()->raw_price)->toBe(6.5);
    });

    it('reads an Excel serial date', function () {
        // A date column formatted as General arrives as a bare number; read as
        // a year it would silently misdate the row by millennia.
        $path = csvFile("Product,Price,Market,Unit,Date\nRice,6.50,Tripoli,kg,46081\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect(Submission::query()->firstOrFail()->observed_at->year)->toBe(2026);
    });

    it('reads day-first and month-first date formats', function () {
        $path = csvFile("Product,Price,Market,Unit,Date\nRice,6.50,Tripoli,kg,15/03/2026\n");

        (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect(Submission::query()->firstOrFail()->observed_at->toDateString())->toBe('2026-03-15');
    });
});

describe('a malformed file produces per-row errors, not a failure', function () {
    it('imports the good rows and reports the bad ones', function () {
        // The headline requirement for this phase.
        $path = csvFile(implode("\n", [
            'Product,Price,Market,Unit,Date',
            'Rice,6.50,Tripoli,kg,2026-03-01',
            'Flour,not-a-number,Sabha,kg,2026-03-01',
            'Oil,9.00,Atlantis,l,2026-03-01',
            ',5.00,Tripoli,kg,2026-03-01',
            'Sugar,-3.00,Tripoli,kg,2026-03-01',
            'Eggs,24.00,Benghazi,piece,2026-03-01',
        ])."\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect($batch->status)->toBe(IngestionBatch::STATUS_COMPLETED)
            ->and($batch->row_count)->toBe(6)
            ->and($batch->accepted_count)->toBe(2)
            ->and($batch->rejected_count)->toBe(4)
            ->and(Submission::query()->count())->toBe(2);
    });

    it('names the row, the column and what is wrong', function () {
        $path = csvFile("Product,Price,Market\nFlour,not-a-number,Sabha\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());
        $errors = $batch->errorRows();

        expect($errors)->toHaveCount(1)
            ->and($errors[0]['row'])->toBe(2)
            ->and($errors[0]['column'])->toBe('Price')
            ->and($errors[0]['message'])->toContain('not-a-number');
    });

    it('names the unmatched location so it can be fixed', function () {
        // "Unknown location" without the value is useless to whoever has to
        // correct the file.
        $path = csvFile("Product,Price,Market\nOil,9.00,Atlantis\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect($batch->errorRows()[0]['message'])->toContain('Atlantis');
    });

    it('rejects a future date', function () {
        $path = csvFile("Product,Price,Market,Unit,Date\nRice,6.50,Tripoli,kg,2099-01-01\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect($batch->errorRows()[0]['message'])->toContain('future');
    });

    it('rejects an unconfigured unit', function () {
        $path = csvFile("Product,Price,Market,Unit,Date\nRice,6.50,Tripoli,furlong,2026-03-01\n");

        $batch = (new PartnerFileImporter)->import($this->source, $path, defaultMapping());

        expect($batch->errorRows()[0]['message'])->toContain('furlong');
    });

    it('fails the batch, not the request, when the mapping is incomplete', function () {
        $path = csvFile("Product,Price,Market\nRice,6.50,Tripoli\n");

        $batch = (new PartnerFileImporter)->import(
            $this->source,
            $path,
            ColumnMapping::fromArray(['item' => 'Product']),
        );

        expect($batch->status)->toBe(IngestionBatch::STATUS_FAILED)
            ->and($batch->error_report['fatal'])->toContain('incomplete');
    });

    it('fails the batch, not the request, when the file cannot be read at all', function () {
        $batch = (new PartnerFileImporter)->import($this->source, '/nowhere/missing.csv', defaultMapping());

        expect($batch->status)->toBe(IngestionBatch::STATUS_FAILED)
            ->and($batch->error_report['fatal'])->not->toBeEmpty();
    });

    it('bounds the error report on a file where everything is wrong', function () {
        // A 250,000-entry report helps nobody.
        $rows = ['Product,Price,Market'];
        for ($i = 0; $i < 600; $i++) {
            $rows[] = "Item {$i},nope,Nowhere";
        }

        $batch = (new PartnerFileImporter)->import($this->source, csvFile(implode("\n", $rows)."\n"), defaultMapping());

        expect(count($batch->errorRows()))->toBeLessThanOrEqual(1200)
            ->and($batch->error_report['truncated'])->toBeTrue();
    });
});

describe('re-uploading the same file', function () {
    it('is recognised rather than reprocessed', function () {
        // Partners resend files. Doubling every price in one would be a silent
        // corruption of the index.
        $contents = "Product,Price,Market\nRice,6.50,Tripoli\nFlour,4.20,Sabha\n";
        $importer = new PartnerFileImporter;

        $first = $importer->import($this->source, csvFile($contents), defaultMapping());
        $second = $importer->import($this->source, csvFile($contents), defaultMapping());

        expect($second->id)->toBe($first->id)
            ->and(Submission::query()->count())->toBe(2)
            ->and(IngestionBatch::query()->count())->toBe(1);
    });

    it('treats a genuinely different file as a new batch', function () {
        $importer = new PartnerFileImporter;

        $importer->import($this->source, csvFile("Product,Price,Market\nRice,6.50,Tripoli\n"), defaultMapping());
        $importer->import($this->source, csvFile("Product,Price,Market\nFlour,4.20,Sabha\n"), defaultMapping());

        expect(IngestionBatch::query()->count())->toBe(2)
            ->and(Submission::query()->count())->toBe(2);
    });

    it('gives the same row of the same file a stable idempotency key', function () {
        // Derived from the checksum and row number rather than randomly, so the
        // property survives even if the batch-level check is bypassed.
        $contents = "Product,Price,Market\nRice,6.50,Tripoli\n";

        $importer = new PartnerFileImporter;
        $importer->import($this->source, csvFile($contents), defaultMapping());
        $keyA = Submission::query()->firstOrFail()->client_idempotency_key;

        Submission::query()->delete();
        IngestionBatch::query()->delete();

        $importer->import($this->source, csvFile($contents), defaultMapping());
        $keyB = Submission::query()->firstOrFail()->client_idempotency_key;

        expect($keyB)->toBe($keyA);
    });
});
