<?php

// SPDX-License-Identifier: Apache-2.0

declare(strict_types=1);

namespace App\Support\Media;

/**
 * Removes everything from a photograph except the picture.
 *
 * A reporter photographs a price tag in a market. Their phone writes the
 * coordinates, the timestamp to the second, and often the device's serial
 * number into the file. That is a record of where a specific person stood on a
 * specific afternoon, attached to a submission that names their location
 * anyway — and in the economies this platform is built for, that is not an
 * abstract risk to the person holding the phone.
 *
 * `SECURITY.md` used to make this the operator's job. An operator who forgets
 * has no way to discover the omission, and the people it exposes are not the
 * ones who would notice. So the platform does it on ingest, before the file is
 * written, and the operator cannot forget.
 *
 * **Lossless, not re-encoded.** Decoding and re-encoding would strip metadata
 * too, and would also soften the photograph — which is the one thing a reviewer
 * needs, because they are reading small print off a price tag. Both formats
 * below are containers of discrete segments, so the metadata can be lifted out
 * and the compressed image data passed through untouched.
 */
final class ImageMetadataStripper
{
    /**
     * JPEG markers carrying metadata rather than picture.
     *
     * APP1 is EXIF and XMP — the GPS lives there. APP2 is ICC and Flashpix,
     * APP13 is IPTC/Photoshop, and the rest of the APPn range is a grab-bag
     * of vendor blocks that have carried location before. APP0 is kept: it is
     * the JFIF header describing pixel density, and some decoders expect it.
     *
     * @var list<int>
     */
    private const JPEG_METADATA_MARKERS = [
        0xE1, 0xE2, 0xE3, 0xE4, 0xE5, 0xE6, 0xE7, 0xE8,
        0xE9, 0xEA, 0xEB, 0xEC, 0xED, 0xEE, 0xEF,
        0xFE, // COM, a free-text comment
    ];

    /**
     * PNG chunks carrying metadata rather than picture.
     *
     * @var list<string>
     */
    private const PNG_METADATA_CHUNKS = ['eXIf', 'tEXt', 'iTXt', 'zTXt', 'tIME'];

    /**
     * Strip a supported image, or return null if it is not one.
     *
     * Null rather than the original bytes: a format this cannot clean is a
     * format whose metadata would survive, and passing it through would be the
     * quiet failure this class exists to prevent.
     */
    public function strip(string $binary): ?string
    {
        return match (true) {
            str_starts_with($binary, "\xFF\xD8") => $this->stripJpeg($binary),
            str_starts_with($binary, "\x89PNG\r\n\x1A\n") => $this->stripPng($binary),
            default => null,
        };
    }

    public function supports(string $binary): bool
    {
        return str_starts_with($binary, "\xFF\xD8")
            || str_starts_with($binary, "\x89PNG\r\n\x1A\n");
    }

    /**
     * Walk the JPEG's segments, copying everything that is not metadata.
     */
    private function stripJpeg(string $binary): ?string
    {
        $length = strlen($binary);
        $output = "\xFF\xD8";
        $offset = 2;

        while ($offset < $length - 1) {
            if ($binary[$offset] !== "\xFF") {
                // Not where a marker should be. A file this malformed is not
                // one to guess about.
                return null;
            }

            $marker = ord($binary[$offset + 1]);

            // Start of scan: everything after this is entropy-coded image data
            // to the end of the file, and carries no metadata segments.
            if ($marker === 0xDA) {
                return $output.substr($binary, $offset);
            }

            if ($marker === 0xD9) {
                return $output."\xFF\xD9";
            }

            // Standalone markers: no length field follows.
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $output .= substr($binary, $offset, 2);
                $offset += 2;

                continue;
            }

            if ($offset + 4 > $length) {
                return null;
            }

            $size = unpack('n', substr($binary, $offset + 2, 2))[1];

            if ($size < 2 || $offset + 2 + $size > $length) {
                return null;
            }

            if (! in_array($marker, self::JPEG_METADATA_MARKERS, true)) {
                $output .= substr($binary, $offset, 2 + $size);
            }

            $offset += 2 + $size;
        }

        return $output;
    }

    /**
     * Walk the PNG's chunks, copying everything that is not metadata.
     */
    private function stripPng(string $binary): ?string
    {
        $length = strlen($binary);
        $output = substr($binary, 0, 8);
        $offset = 8;

        while ($offset + 8 <= $length) {
            $size = unpack('N', substr($binary, $offset, 4))[1];
            $type = substr($binary, $offset + 4, 4);

            // 4 length + 4 type + data + 4 CRC.
            $total = 12 + $size;

            if ($offset + $total > $length) {
                return null;
            }

            if (! in_array($type, self::PNG_METADATA_CHUNKS, true)) {
                $output .= substr($binary, $offset, $total);
            }

            $offset += $total;

            if ($type === 'IEND') {
                break;
            }
        }

        return $output;
    }
}
