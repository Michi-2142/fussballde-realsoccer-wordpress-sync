<?php
/**
 * Fussball_Font_Decoder
 *
 * fussball.de obfuscates scores by rendering digits through a page-specific
 * custom TrueType font. Each scoring <span> contains a single Private Use
 * Area (PUA) Unicode character and carries a `data-obfuscation` attribute
 * that is the unique ID of the font used for that page.
 *
 * This class:
 *  1. Downloads the TTF font for a given obfuscation key.
 *  2. Parses the cmap (format 4) table to map PUA code-points → glyph IDs.
 *  3. Parses the post (format 2) table to map glyph IDs → glyph names.
 *  4. Matches glyph names against the digit names ("zero"–"nine") to build a
 *     final code-point → digit (0-9) lookup table.
 *  5. Caches the lookup table in a WordPress transient (24 h TTL).
 */
defined( 'ABSPATH' ) || exit;

class Fussball_Font_Decoder {

    /**
     * Standard PostScript glyph names for 0-9 (ordered by digit value).
     */
    private static $digit_names = array(
        'zero', 'one', 'two', 'three', 'four',
        'five', 'six', 'seven', 'eight', 'nine',
    );

    /**
     * Standard Mac glyph names (first 258 entries used in post format 2).
     * Only enough entries to cover digits (indices 19-28).
     */
    private static $mac_glyph_names = array(
        0  => '.notdef',
        1  => '.null',
        2  => 'nonmarkingreturn',
        3  => 'space',
        4  => 'exclam',
        5  => 'quotedbl',
        6  => 'numbersign',
        7  => 'dollar',
        8  => 'percent',
        9  => 'ampersand',
        10 => 'quotesingle',
        11 => 'parenleft',
        12 => 'parenright',
        13 => 'asterisk',
        14 => 'plus',
        15 => 'comma',
        16 => 'hyphen',
        17 => 'period',
        18 => 'slash',
        19 => 'zero',
        20 => 'one',
        21 => 'two',
        22 => 'three',
        23 => 'four',
        24 => 'five',
        25 => 'six',
        26 => 'seven',
        27 => 'eight',
        28 => 'nine',
    );

    // ──────────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Convert a single PUA UTF-8 character to its digit (0-9).
     *
     * Returns -1 on failure (character not found in font or font unavailable).
     *
     * @param string $utf8_char      Single UTF-8 character from a score span.
     * @param string $obfuscation_key The data-obfuscation attribute value.
     * @return int  0-9 or -1
     */
    public static function decode_char( $utf8_char, $obfuscation_key ) {
        if ( empty( $utf8_char ) || empty( $obfuscation_key ) ) {
            return -1;
        }

        $mapping = self::get_mapping( $obfuscation_key );
        if ( empty( $mapping ) ) {
            return -1;
        }

        $codepoint = self::utf8_to_codepoint( $utf8_char );
        return isset( $mapping[ $codepoint ] ) ? (int) $mapping[ $codepoint ] : -1;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Mapping retrieval (cached)
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Returns an associative array: [ unicode_codepoint => digit (0-9) ]
     * for the given obfuscation key. Cached in a transient.
     *
     * @param string $key
     * @return array
     */
    public static function get_mapping( $key ) {
        $transient_key = 'fussball_font_' . sanitize_key( $key );
        $cached        = get_transient( $transient_key );
        if ( false !== $cached && is_array( $cached ) ) {
            return $cached;
        }

        $font_url = 'https://www.fussball.de/export.fontface/-/format/ttf/id/' . rawurlencode( $key ) . '/type/font';

        $response = wp_remote_get( $font_url, array(
            'timeout'    => 15,
            'user-agent' => 'Mozilla/5.0 (compatible; WordPress/' . get_bloginfo( 'version' ) . ')',
        ) );

        if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
            return array();
        }

        $font_data = wp_remote_retrieve_body( $response );
        if ( strlen( $font_data ) < 16 ) {
            return array();
        }

        $mapping = self::parse_ttf( $font_data );

        // Cache for 24 h – font files are immutable for a given key
        set_transient( $transient_key, $mapping, DAY_IN_SECONDS );

        return $mapping;
    }

    // ──────────────────────────────────────────────────────────────────────
    // TTF Parsing
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Parse a TTF binary and return [ codepoint => digit ] for PUA chars.
     *
     * @param string $data Raw TTF binary.
     * @return array
     */
    private static function parse_ttf( $data ) {
        if ( strlen( $data ) < 12 ) {
            return array();
        }

        // ── Offset table ──────────────────────────────────────────────────
        // 4  scaler   (ignored)
        // 2  numTables
        // 6  searchRange / entrySelector / rangeShift  (ignored)
        $num_tables = self::u16( $data, 4 );

        $tables = array();
        for ( $i = 0; $i < $num_tables; $i++ ) {
            $base = 12 + $i * 16;
            if ( $base + 16 > strlen( $data ) ) {
                break;
            }
            $tag              = substr( $data, $base, 4 );
            $table_offset     = self::u32( $data, $base + 8 );
            $tables[ $tag ]   = $table_offset;
        }

        if ( ! isset( $tables['cmap'] ) || ! isset( $tables['post'] ) ) {
            return array();
        }

        // ── Step 1: build PUA codepoint → glyph-id map via cmap ──────────
        $pua_to_glyph = self::parse_cmap( $data, $tables['cmap'] );

        if ( empty( $pua_to_glyph ) ) {
            return array();
        }

        // ── Step 2: build glyph-id → digit via post table ─────────────────
        $glyph_to_digit = self::parse_post( $data, $tables['post'] );

        if ( empty( $glyph_to_digit ) ) {
            return array();
        }

        // ── Step 3: combine ────────────────────────────────────────────────
        $mapping = array();
        foreach ( $pua_to_glyph as $codepoint => $glyph_id ) {
            if ( isset( $glyph_to_digit[ $glyph_id ] ) ) {
                $mapping[ $codepoint ] = $glyph_to_digit[ $glyph_id ];
            }
        }

        return $mapping;
    }

    /**
     * Parse the cmap table and return [ codepoint => glyph_id ] for PUA range.
     *
     * @param string $data        Full TTF binary.
     * @param int    $cmap_offset Offset to the cmap table.
     * @return array
     */
    private static function parse_cmap( $data, $cmap_offset ) {
        if ( $cmap_offset + 4 > strlen( $data ) ) {
            return array();
        }

        $num_subtables = self::u16( $data, $cmap_offset + 2 );
        $subtable_offset = null;

        for ( $i = 0; $i < $num_subtables; $i++ ) {
            $entry  = $cmap_offset + 4 + $i * 8;
            if ( $entry + 8 > strlen( $data ) ) {
                break;
            }
            $plat_id  = self::u16( $data, $entry );
            $enc_id   = self::u16( $data, $entry + 2 );
            $rel_off  = self::u32( $data, $entry + 4 );

            // Prefer Windows/BMP (3,1) then Unicode BMP (0,3) then any Unicode (0,*)
            if ( ( $plat_id === 3 && $enc_id === 1 ) ||
                 ( $plat_id === 0 && $enc_id === 3 ) ||
                 ( $plat_id === 0 ) ) {
                $subtable_offset = $cmap_offset + $rel_off;
                if ( $plat_id === 3 && $enc_id === 1 ) {
                    break; // Highest priority – stop searching
                }
            }
        }

        if ( $subtable_offset === null ) {
            return array();
        }

        $format = self::u16( $data, $subtable_offset );

        if ( $format === 4 ) {
            return self::parse_cmap_format4( $data, $subtable_offset );
        }

        return array();
    }

    /**
     * Parse cmap format 4 (BMP Unicode).
     *
     * @param string $data   Full TTF binary.
     * @param int    $offset Offset where the format-4 subtable starts.
     * @return array  [ codepoint => glyph_id ] for PUA range E000-F8FF only.
     */
    private static function parse_cmap_format4( $data, $offset ) {
        // format=4, length, language, segCount_x2, searchRange, entrySelector, rangeShift
        // = 7 × 2 bytes = 14 bytes header
        if ( $offset + 14 > strlen( $data ) ) {
            return array();
        }

        $seg_count_x2 = self::u16( $data, $offset + 6 );
        $seg_count    = (int) ( $seg_count_x2 / 2 );

        if ( $seg_count <= 0 ) {
            return array();
        }

        $end_count_base    = $offset + 14;
        $start_count_base  = $end_count_base + $seg_count * 2 + 2; // +2 = reservedPad
        $delta_base        = $start_count_base + $seg_count * 2;
        $range_off_base    = $delta_base + $seg_count * 2;

        if ( $range_off_base + $seg_count * 2 > strlen( $data ) ) {
            return array();
        }

        $result = array();

        for ( $i = 0; $i < $seg_count; $i++ ) {
            $end   = self::u16( $data, $end_count_base + $i * 2 );
            $start = self::u16( $data, $start_count_base + $i * 2 );

            if ( $start === 0xFFFF ) {
                break; // Terminator segment
            }

            // We only care about the Private Use Area
            if ( $end < 0xE000 || $start > 0xF8FF ) {
                continue;
            }

            $delta     = self::s16( $data, $delta_base + $i * 2 );
            $range_off = self::u16( $data, $range_off_base + $i * 2 );

            $c_start = max( $start, 0xE000 );
            $c_end   = min( $end, 0xF8FF );

            for ( $c = $c_start; $c <= $c_end; $c++ ) {
                if ( $range_off === 0 ) {
                    $glyph_id = ( $c + $delta ) & 0xFFFF;
                } else {
                    // Pointer arithmetic: offset is relative to the position of
                    // idRangeOffset[i] itself in the array.
                    $ptr      = $range_off_base + $i * 2 + $range_off + ( $c - $start ) * 2;
                    if ( $ptr + 2 > strlen( $data ) ) {
                        continue;
                    }
                    $glyph_id = self::u16( $data, $ptr );
                    if ( $glyph_id !== 0 ) {
                        $glyph_id = ( $glyph_id + $delta ) & 0xFFFF;
                    }
                }

                if ( $glyph_id > 0 ) {
                    $result[ $c ] = $glyph_id;
                }
            }
        }

        return $result;
    }

    /**
     * Parse the post table and return [ glyph_id => digit ] for digit glyphs.
     *
     * @param string $data         Full TTF binary.
     * @param int    $post_offset  Offset to the post table.
     * @return array
     */
    private static function parse_post( $data, $post_offset ) {
        if ( $post_offset + 32 > strlen( $data ) ) {
            return array();
        }

        // First 4 bytes = version as fixed-point (2.0 = 0x00020000)
        $version = self::u32( $data, $post_offset );

        if ( $version === 0x00010000 ) {
            // Format 1: all glyphs use standard Mac names in order
            return self::digit_map_from_mac_names( 0 );
        }

        if ( $version === 0x00020000 ) {
            return self::parse_post_v2( $data, $post_offset );
        }

        // Format 3.0 = no glyph names; format 2.5 is deprecated – both unsupported
        return array();
    }

    /**
     * Parse post table format 2 and return [ glyph_id => digit ].
     *
     * @param string $data         Full TTF binary.
     * @param int    $post_offset  Offset to the post table.
     * @return array
     */
    private static function parse_post_v2( $data, $post_offset ) {
        // Header = 32 bytes, then:
        //   numberOfGlyphs (uint16)
        //   glyphNameIndex[numberOfGlyphs] (uint16 each)
        //   Pascal strings for custom names (indices >= 258)
        $header_end = $post_offset + 32;

        if ( $header_end + 2 > strlen( $data ) ) {
            return array();
        }

        $num_glyphs = self::u16( $data, $header_end );
        $index_base = $header_end + 2;

        if ( $index_base + $num_glyphs * 2 > strlen( $data ) ) {
            return array();
        }

        // Read name indices
        $name_indices = array();
        for ( $g = 0; $g < $num_glyphs; $g++ ) {
            $name_indices[ $g ] = self::u16( $data, $index_base + $g * 2 );
        }

        // Read custom Pascal strings (indices >= 258)
        $custom_names = array();
        $ptr          = $index_base + $num_glyphs * 2; // points right after index table

        while ( $ptr < strlen( $data ) ) {
            $len = ord( $data[ $ptr ] );
            $ptr++;
            if ( $ptr + $len > strlen( $data ) ) {
                break;
            }
            $custom_names[] = substr( $data, $ptr, $len );
            $ptr += $len;
        }

        // Build glyph_id → digit map
        $result = array();

        for ( $g = 0; $g < $num_glyphs; $g++ ) {
            $ni   = $name_indices[ $g ];
            $name = '';

            if ( $ni < 258 ) {
                $name = isset( self::$mac_glyph_names[ $ni ] )
                    ? self::$mac_glyph_names[ $ni ]
                    : '';
            } else {
                $custom_idx = $ni - 258;
                $name       = isset( $custom_names[ $custom_idx ] )
                    ? $custom_names[ $custom_idx ]
                    : '';
            }

            $digit = self::name_to_digit( $name );
            if ( $digit >= 0 ) {
                $result[ $g ] = $digit;
            }
        }

        return $result;
    }

    /**
     * Build a glyph_id → digit map from the standard Mac glyph name list
     * (used when post format = 1.0).
     *
     * @param int $start_glyph_id  Glyph ID of the first standard glyph (.notdef).
     * @return array
     */
    private static function digit_map_from_mac_names( $start_glyph_id ) {
        $result = array();
        foreach ( self::$mac_glyph_names as $index => $name ) {
            $digit = self::name_to_digit( $name );
            if ( $digit >= 0 ) {
                $result[ $start_glyph_id + $index ] = $digit;
            }
        }
        return $result;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Map a PostScript glyph name to a digit. Returns -1 if not a digit glyph.
     *
     * Handles: "zero"…"nine", "0"…"9", "uni0030"…"uni0039",
     *          "digit_zero"…"digit_nine" variants.
     *
     * @param string $name
     * @return int
     */
    private static function name_to_digit( $name ) {
        $name = strtolower( trim( $name ) );
        $idx  = array_search( $name, self::$digit_names, true );
        if ( $idx !== false ) {
            return (int) $idx;
        }
        // plain "0"-"9"
        if ( preg_match( '/^(\d)$/', $name, $m ) ) {
            return (int) $m[1];
        }
        // uni0030 – uni0039
        if ( preg_match( '/^uni003([0-9])$/i', $name, $m ) ) {
            return (int) $m[1];
        }
        return -1;
    }

    /**
     * Convert a UTF-8 encoded character to its Unicode code point.
     *
     * @param string $char  One UTF-8 character.
     * @return int  Unicode code point.
     */
    public static function utf8_to_codepoint( $char ) {
        if ( $char === '' ) {
            return 0;
        }
        $bytes = array_values( unpack( 'C*', $char ) );
        $b0    = $bytes[0];

        if ( $b0 < 0x80 ) {
            return $b0;
        }
        if ( $b0 < 0xE0 ) {
            return ( ( $b0 & 0x1F ) << 6 ) | ( $bytes[1] & 0x3F );
        }
        if ( $b0 < 0xF0 ) {
            return ( ( $b0 & 0x0F ) << 12 )
                 | ( ( $bytes[1] & 0x3F ) << 6 )
                 |   ( $bytes[2] & 0x3F );
        }
        return ( ( $b0 & 0x07 ) << 18 )
             | ( ( $bytes[1] & 0x3F ) << 12 )
             | ( ( $bytes[2] & 0x3F ) << 6 )
             |   ( $bytes[3] & 0x3F );
    }

    // ── Binary helpers ───────────────────────────────────────────────────

    /** Read unsigned 16-bit big-endian integer. */
    private static function u16( $data, $offset ) {
        return unpack( 'n', substr( $data, $offset, 2 ) )[1];
    }

    /** Read unsigned 32-bit big-endian integer. */
    private static function u32( $data, $offset ) {
        return unpack( 'N', substr( $data, $offset, 4 ) )[1];
    }

    /** Read signed 16-bit big-endian integer. */
    private static function s16( $data, $offset ) {
        $v = unpack( 'n', substr( $data, $offset, 2 ) )[1];
        return ( $v > 32767 ) ? $v - 65536 : $v;
    }
}
