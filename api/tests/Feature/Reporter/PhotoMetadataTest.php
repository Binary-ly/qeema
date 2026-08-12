<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

use App\Models\Country;
use App\Models\Location;
use App\Models\Submission;
use App\Support\CountryConfig\CountryConfigImporter;
use App\Support\CountryConfig\CountryConfigLoader;
use App\Support\Media\ImageMetadataStripper;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/*
|--------------------------------------------------------------------------
| What a photograph is allowed to carry
|--------------------------------------------------------------------------
|
| A reporter photographs a price tag in a market. Their phone writes the
| coordinates, the timestamp to the second and often the device's serial number
| into the file. That is a record of where a particular person stood on a
| particular afternoon — and the people it would expose are not the ones who
| would think to check.
|
| SECURITY.md used to make stripping it the operator's responsibility. An
| operator who forgets has no way to find out. So it happens on ingest, and
| these tests are what keep it happening.
|
*/

/**
 * A real JPEG carrying a real EXIF block with GPS coordinates.
 *
 * Built byte by byte rather than committed as a fixture, so what is being
 * stripped is visible in the test rather than hidden in a binary nobody can
 * read in a diff.
 */
function jpegWithGps(string $marker = 'SECRET-GPS-PAYLOAD'): string
{
    // APP1: "Exif\0\0" then a TIFF header, then whatever payload we want to
    // prove disappears. The structure only has to be well-formed enough to be
    // a segment; the stripper removes APP1 wholesale.
    $exif = "Exif\x00\x00MM\x00\x2A\x00\x00\x00\x08".$marker;
    $app1 = "\xFF\xE1".pack('n', strlen($exif) + 2).$exif;

    // APP0/JFIF, which must survive: it describes pixel density.
    $jfif = "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    $app0 = "\xFF\xE0".pack('n', strlen($jfif) + 2).$jfif;

    // A comment segment, also metadata.
    $comment = 'taken by a named person';
    $com = "\xFF\xFE".pack('n', strlen($comment) + 2).$comment;

    // A minimal frame header so the file is structurally a picture.
    $sof = "\xFF\xC0".pack('n', 11)."\x08\x00\x01\x00\x01\x01\x01\x11\x00";

    // Start of scan, then "image data" through to end of image.
    $sos = "\xFF\xDA".pack('n', 8)."\x01\x01\x00\x00\x3F\x00";

    return "\xFF\xD8".$app0.$app1.$com.$sof.$sos."IMAGEDATA\xFF\xD9";
}

function pngWithMetadata(string $marker = 'SECRET-GPS-PAYLOAD'): string
{
    $chunk = function (string $type, string $data): string {
        return pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));
    };

    return "\x89PNG\r\n\x1A\n"
        .$chunk('IHDR', pack('N', 1).pack('N', 1)."\x08\x00\x00\x00\x00")
        .$chunk('eXIf', $marker)
        .$chunk('tEXt', "Comment\x00".$marker)
        .$chunk('IDAT', 'PIXELS')
        .$chunk('IEND', '');
}

describe('the stripper', function (): void {
    it('removes GPS coordinates from a JPEG', function (): void {
        $clean = (new ImageMetadataStripper)->strip(jpegWithGps());

        expect($clean)->not->toBeNull()
            ->and($clean)->not->toContain('SECRET-GPS-PAYLOAD')
            ->and($clean)->not->toContain('Exif');
    });

    it('removes a free-text comment too', function (): void {
        // Not only GPS: the comment field is where camera apps and editors put
        // whatever they like, including names.
        $clean = (new ImageMetadataStripper)->strip(jpegWithGps());

        expect($clean)->not->toContain('taken by a named person');
    });

    it('leaves the picture itself untouched', function (): void {
        // Lossless is the point. A reviewer is reading small print off a price
        // tag, and a re-encode would soften exactly that.
        $clean = (new ImageMetadataStripper)->strip(jpegWithGps());

        expect($clean)->toContain('IMAGEDATA')
            ->and($clean)->toStartWith("\xFF\xD8")
            ->and($clean)->toEndWith("\xFF\xD9");
    });

    it('keeps the JFIF header, which describes the picture rather than the person', function (): void {
        $clean = (new ImageMetadataStripper)->strip(jpegWithGps());

        expect($clean)->toContain('JFIF');
    });

    it('removes EXIF and text chunks from a PNG', function (): void {
        $clean = (new ImageMetadataStripper)->strip(pngWithMetadata());

        expect($clean)->not->toBeNull()
            ->and($clean)->not->toContain('SECRET-GPS-PAYLOAD')
            ->and($clean)->toContain('PIXELS')
            ->and($clean)->toContain('IHDR');
    });

    it('refuses a format it cannot clean rather than passing it through', function (): void {
        // Returning the original would be the quiet failure this exists to
        // prevent: a file whose metadata survived, stored as though it had not.
        expect((new ImageMetadataStripper)->strip('GIF89a whatever'))->toBeNull()
            ->and((new ImageMetadataStripper)->strip('<svg onload="alert(1)"/>'))->toBeNull();
    });

    it('refuses a malformed file rather than guessing at its structure', function (): void {
        expect((new ImageMetadataStripper)->strip("\xFF\xD8\x00\x00not a segment"))->toBeNull();
    });
});

describe('what reaches the disk', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');

        (new CountryConfigImporter)->import(
            (new CountryConfigLoader)->load(base_path('../countries/ly.yaml'))
        );

        $this->country = Country::query()->where('code', 'LY')->firstOrFail();
        $this->location = Location::query()->where('country_id', $this->country->id)->firstOrFail();
    });

    function submitWithPhoto(UploadedFile $photo): TestResponse
    {
        return test()->post('/api/v1/submissions', [
            'reporter_ref' => (string) Str::uuid(),
            'country' => 'LY',
            'location_slug' => test()->location->slug,
            'item_text' => 'أرز',
            'price' => 9.5,
            'client_idempotency_key' => (string) Str::uuid(),
            'photo' => $photo,
        ], ['Accept' => 'application/json']);
    }

    it('never writes a photograph that still knows where it was taken', function (): void {
        $photo = UploadedFile::fake()->createWithContent('price.jpg', jpegWithGps());

        submitWithPhoto($photo)->assertCreated();

        $submission = Submission::query()->firstOrFail();

        expect($submission->photo_path)->not->toBeNull();

        $stored = Storage::disk('local')->get($submission->photo_path);

        // The whole point, stated as plainly as it can be.
        expect($stored)->not->toContain('SECRET-GPS-PAYLOAD')
            ->and($stored)->toContain('IMAGEDATA');
    });

    it('accepts the price even when the photograph has to be dropped', function (): void {
        // The price is the contribution; the picture is corroboration. Losing
        // the second must not lose the first — a reporter on a bad connection
        // has already spent their data sending it.
        $photo = UploadedFile::fake()->createWithContent('price.jpg', "\xFF\xD8\x00\x00broken");

        submitWithPhoto($photo)->assertCreated();

        $submission = Submission::query()->firstOrFail();

        expect($submission->photo_path)->toBeNull()
            ->and((float) $submission->raw_price)->toBe(9.5);
    });

    it('refuses an SVG, which is a document rather than a photograph', function (): void {
        $svg = UploadedFile::fake()->createWithContent('price.svg', '<svg onload="alert(1)"/>');

        submitWithPhoto($svg)->assertStatus(422);
    });
});
