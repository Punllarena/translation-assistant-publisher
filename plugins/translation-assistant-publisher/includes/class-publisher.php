<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Publisher {

    private const NAV_BLOCK = "<!-- wp:html -->\n<div id=\"textbox\">\n<p class=\"alignleft\">[previous_page]</p>\n<p class=\"alignright\">[next_page]</p>\n</div>\n<!-- /wp:html -->";
    private const SEPARATOR = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

    public function publish( array $data, int $user_id ): array|WP_Error {
        $series_slug  = sanitize_title( $data['series_slug'] );
        $chapter_idx  = (int) $data['chapter_index'];

        // Client may send chapter_title already prefixed with the series short title — strip it
        $data['chapter_title'] = preg_replace(
            '/^' . preg_quote( $data['series_title_short'], '/' ) . '\s+/i',
            '',
            $data['chapter_title']
        );

        // Snapshot before find_or_create so we can tell if this is a first-time synopsis
        $pre_existing        = get_page_by_path( $series_slug, OBJECT, 'page' );
        $already_has_content = $pre_existing && ! empty( trim( $pre_existing->post_content ) );

        $index_id = $this->find_or_create_index_page(
            $series_slug, $data['series_title'], $data['series_link'], $user_id
        );
        if ( is_wp_error( $index_id ) ) return $index_id;

        if ( $chapter_idx === 0 ) {
            $result = $this->handle_synopsis( $index_id, $data['series_title'], $data['series_link'], $data['chapter_body'] );
            if ( is_wp_error( $result ) ) return $result;
            return [
                'status'   => 'ok',
                'page_url' => get_permalink( $index_id ),
                'post_url' => null,
                'created'  => ! $already_has_content,
            ];
        }

        // Idempotency / renumber check
        $existing   = $this->chapter_exists( $series_slug, $chapter_idx );
        $renumbered = false;
        if ( isset( $data['previous_chapter_index'] ) ) {
            $prev = $this->chapter_exists( $series_slug, (int) $data['previous_chapter_index'] );
            if ( $prev && $existing && $prev->ID !== $existing->ID ) {
                return new WP_Error(
                    'renumber_conflict',
                    sprintf(
                        'Chapter %d already exists; cannot renumber chapter %d into it.',
                        $chapter_idx,
                        (int) $data['previous_chapter_index']
                    )
                );
            }
            if ( ! $existing && $prev ) {
                $existing   = $prev;
                $renumbered = true;
            }
        }

        if ( $existing ) {
            $old_url = get_permalink( $existing->ID ); // capture before any rename

            if ( $renumbered ) {
                $rename_result = wp_update_post( [
                    'ID'         => $existing->ID,
                    'post_name'  => "{$series_slug}-c{$chapter_idx}",
                    'menu_order' => $chapter_idx,
                ], true );
                if ( is_wp_error( $rename_result ) ) return $rename_result;
            }

            $update_result = $this->update_chapter_page( $existing->ID, $data );
            if ( is_wp_error( $update_result ) ) return $update_result;

            $new_url = get_permalink( $existing->ID );
            $this->update_toc_entry( $index_id, $old_url, $data['chapter_title'], $new_url );

            $linked_post_id = (int) get_post_meta( $existing->ID, '_tap_linked_post_id', true );
            if ( $linked_post_id && get_post( $linked_post_id ) ) {
                $post_result = $this->update_post( $linked_post_id, $data, $new_url );
                if ( is_wp_error( $post_result ) ) return $post_result;
            } else {
                // The meta is missing for two different reasons: the post genuinely
                // failed to be created, or the chapter predates 1.5.0, when the meta
                // was only written for password-protected chapters. Look for the post
                // before assuming it does not exist — creating a second one publishes
                // a duplicate to the feed.
                $adopted = $this->find_existing_teaser_post( $data );
                if ( $adopted ) {
                    update_post_meta( $existing->ID, '_tap_linked_post_id', $adopted );
                    $linked_post_id = $adopted;
                    $post_result    = $this->update_post( $linked_post_id, $data, $new_url );
                    if ( is_wp_error( $post_result ) ) return $post_result;
                } else {
                    $linked_post_id = $this->create_post( $data, $new_url, $user_id );
                    if ( is_wp_error( $linked_post_id ) ) return $linked_post_id;
                    update_post_meta( $existing->ID, '_tap_linked_post_id', $linked_post_id );
                }
            }

            if ( isset( $data['unlock_chapter_index'] ) ) {
                $this->unlock_chapter( $series_slug, (int) $data['unlock_chapter_index'] );
            }

            return [
                'status'    => 'ok',
                'page_url'  => $new_url,
                'post_url'  => get_permalink( $linked_post_id ),
                'created'   => false,
                'updated'   => true,
                'scheduled' => ! empty( $data['publish_date'] ),
            ];
        }

        $chapter_id = $this->create_chapter_page( $data, $index_id, $user_id );
        if ( is_wp_error( $chapter_id ) ) return new WP_Error( 'page_failed', 'Failed to create page' );

        $chapter_url = get_permalink( $chapter_id );
        $this->append_toc_entry( $index_id, $data['chapter_title'], $chapter_url );

        $post_id = $this->create_post( $data, $chapter_url, $user_id );
        if ( is_wp_error( $post_id ) ) return new WP_Error( 'post_failed', 'Failed to create post' );

        update_post_meta( $chapter_id, '_tap_linked_post_id', $post_id );

        if ( isset( $data['unlock_chapter_index'] ) ) {
            $this->unlock_chapter( $series_slug, (int) $data['unlock_chapter_index'] );
        }

        return [
            'status'    => 'ok',
            'page_url'  => get_permalink( $chapter_id ),
            'post_url'  => get_permalink( $post_id ),
            'created'   => true,
            'updated'   => false,
            'scheduled' => ! empty( $data['publish_date'] ),
        ];
    }

    private function find_existing_teaser_post( array $data ): int {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;
        if ( ! $child_cat ) return 0;

        $found = get_posts( [
            'post_type'   => 'post',
            'post_status' => [ 'publish', 'draft', 'future', 'pending', 'private' ],
            'title'       => $title,
            'category'    => $child_cat,
            'numberposts' => 2,
            'fields'      => 'ids',
        ] );

        // Exactly one unambiguous match is adoptable. Zero means it really was
        // never created; two or more means we cannot tell which is canonical,
        // and guessing would compound the very duplication this prevents.
        return count( $found ) === 1 ? (int) $found[0] : 0;
    }

    private function apply_schedule( array $args, array $data, bool $is_page = false ): array {
        if ( ! empty( $data['publish_date'] ) ) {
            $dt = new DateTime( $data['publish_date'], new DateTimeZone( 'UTC' ) );
            $args['post_date_gmt'] = $dt->format( 'Y-m-d H:i:s' );
            $dt->setTimezone( wp_timezone() );
            $args['post_date']   = $dt->format( 'Y-m-d H:i:s' );
            $args['post_status'] = 'future';
            return $args;
        }

        // No publish_date: go live now. Without this branch, post_status is a
        // one-way ratchet — a chapter scheduled once can never be unscheduled.
        // Pages are never password-gated into draft — protection is the
        // post_password field, not status — only teaser posts use that rule.
        $args['post_status'] = $is_page || empty( $data['password'] ) ? 'publish' : 'draft';
        $args['post_date']     = current_time( 'mysql' );
        $args['post_date_gmt'] = current_time( 'mysql', true );
        return $args;
    }

    public function create_post( array $data, string $chapter_url, int $user_id ): int|WP_Error {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;
        $content     = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                     . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">Read Chapter ' . $chapter_idx . '</a></p>';

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;

        $post_args = [
            'post_type'     => 'post',
            'post_title'    => $title,
            'post_author'   => $user_id,
            'post_content'  => $content,
            'post_category' => $child_cat ? [ $child_cat ] : [],
        ];

        $post_args = $this->apply_schedule( $post_args, $data );

        return wp_insert_post( $post_args, true );
    }

    public function update_post( int $post_id, array $data, string $chapter_url ): int|WP_Error {
        $chapter_idx = (int) $data['chapter_index'];
        $title       = $data['series_title_short'] . ' Chapter ' . $chapter_idx;

        // The teaser body is wholly derived from the payload — there is no
        // human-authored content to preserve — so rebuild it exactly the way
        // create_post does rather than patching one line of it.
        $content = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                 . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">Read Chapter ' . $chapter_idx . '</a></p>';

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;

        $post_args = [
            'ID'            => $post_id,
            'post_title'    => $title,
            'post_content'  => $content,
            'post_category' => $child_cat ? [ $child_cat ] : [],
        ];

        $post_args = $this->apply_schedule( $post_args, $data );

        return wp_update_post( $post_args, true );
    }

    private function get_or_create_category( string $name, int $parent = 0 ): int {
        $term = term_exists( $name, 'category', $parent );
        if ( $term ) return (int) $term['term_id'];
        $result = wp_insert_term( $name, 'category', [ 'parent' => $parent ] );
        return is_wp_error( $result ) ? 0 : (int) $result['term_id'];
    }

    public function chapter_exists( string $series_slug, int $chapter_index ): WP_Post|false {
        $slug = "{$series_slug}-c{$chapter_index}";
        // Page is a child of the index page, so the full path is parent/child.
        $page = get_page_by_path( "{$series_slug}/{$slug}", OBJECT, 'page' );
        return $page ?: false;
    }

    public function get_chapter_status( string $series_slug, int $chapter_idx ): array {
        if ( $chapter_idx === 0 ) {
            $page = get_page_by_path( $series_slug, OBJECT, 'page' );
        } else {
            $page = $this->chapter_exists( $series_slug, $chapter_idx );
        }

        if ( ! $page ) {
            return [ 'status' => 'not_found', 'post_url' => null ];
        }

        return [
            'status'   => $page->post_status,
            'post_url' => get_permalink( $page->ID ),
        ];
    }

    public function create_chapter_page( array $data, int $index_id, int $user_id ): int|WP_Error {
        $series_slug = sanitize_title( $data['series_slug'] );
        $chapter_idx = (int) $data['chapter_index'];
        $slug        = "{$series_slug}-c{$chapter_idx}";

        $args = [
            'post_type'      => 'page',
            'post_title'     => $data['series_title_short'] . ' ' . $chapter_idx . ' - ' . $data['chapter_title'],
            'post_name'      => $slug,
            'post_parent'    => $index_id,
            'post_author'    => $user_id,
            'post_content'   => '',
            'comment_status' => 'open',
            'menu_order'     => $chapter_idx,
        ];

        if ( ! empty( $data['password'] ) ) {
            $args['post_password'] = sanitize_text_field( $data['password'] );
        }

        $args = $this->apply_schedule( $args, $data, true );

        $chapter_id = wp_insert_post( $args, true );
        if ( is_wp_error( $chapter_id ) ) return $chapter_id;

        $content = $this->convert_to_blocks(
            $data['chapter_body'],
            $data['images'] ?? [],
            $data['cover'] ?? null,
            $chapter_id
        );
        $update_result = wp_update_post( [ 'ID' => $chapter_id, 'post_content' => $content ], true );
        if ( is_wp_error( $update_result ) ) {
            wp_delete_post( $chapter_id, true );
            return $update_result;
        }

        return $chapter_id;
    }

    public function update_chapter_page( int $chapter_id, array $data ): bool|WP_Error {
        $attachments = get_posts( [
            'post_type'   => 'attachment',
            'post_parent' => $chapter_id,
            'numberposts' => -1,
            'post_status' => 'any',
        ] );
        foreach ( $attachments as $attachment ) {
            wp_delete_attachment( $attachment->ID, true );
        }

        $content = $this->convert_to_blocks(
            $data['chapter_body'],
            $data['images'] ?? [],
            $data['cover'] ?? null,
            $chapter_id
        );

        $args = [
            'ID'           => $chapter_id,
            'post_title'   => $data['series_title_short'] . ' ' . (int) $data['chapter_index'] . ' - ' . $data['chapter_title'],
            'post_content' => $content,
        ];

        // Absence never means "unlock" — unlocking is the explicit
        // unlock_chapter_index operation. Only apply what was sent.
        if ( ! empty( $data['password'] ) ) {
            $args['post_password'] = sanitize_text_field( $data['password'] );
        }

        $args = $this->apply_schedule( $args, $data, true );

        $result = wp_update_post( $args, true );
        if ( is_wp_error( $result ) ) return $result;
        return true;
    }

    public function unlock_chapter( string $series_slug, int $chapter_index ): void {
        $page = $this->chapter_exists( $series_slug, $chapter_index );
        if ( ! $page ) return;
        if ( empty( $page->post_password ) ) return;
        wp_update_post( [
            'ID'            => $page->ID,
            'post_password' => '',
        ] );
        $post_id = (int) get_post_meta( $page->ID, '_tap_linked_post_id', true );
        if ( $post_id ) {
            wp_update_post( [
                'ID'          => $post_id,
                'post_status' => 'publish',
            ] );
        }
    }

    public function attach_image( string $filename, string $mime, string $base64, int $post_id ): int|WP_Error {
        $decoded = base64_decode( $base64, true );
        if ( $decoded === false ) {
            return new WP_Error( 'bad_image_data', "Could not decode image data for {$filename}" );
        }

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $tmp_file = wp_tempnam( $filename );
        if ( ! $tmp_file || file_put_contents( $tmp_file, $decoded ) === false ) {
            return new WP_Error( 'temp_write_failed', "Could not write temp file for {$filename}" );
        }

        $sanitized_name = sanitize_file_name( $filename );
        $filetype       = wp_check_filetype( $sanitized_name );
        if ( empty( $filetype['ext'] ) ) {
            $ext = wp_get_default_extension_for_mime_type( $mime );
            if ( ! empty( $ext ) ) {
                $sanitized_name .= '.' . $ext;
            }
        }

        $file_array = [
            'name'     => $sanitized_name,
            'tmp_name' => $tmp_file,
        ];

        $attachment_id = media_handle_sideload( $file_array, $post_id );
        if ( is_wp_error( $attachment_id ) ) {
            if ( file_exists( $tmp_file ) ) {
                @unlink( $tmp_file );
            }
            return $attachment_id;
        }

        return $attachment_id;
    }

    public function append_toc_entry( int $index_id, string $chapter_title, string $chapter_url ): void {
        $page    = get_post( $index_id );
        if ( ! $page ) return;
        $content = $page->post_content;
        $entry   = "<!-- wp:paragraph -->\n<p><a href=\"" . esc_url( $chapter_url ) . '">' . esc_html( $chapter_title ) . "</a></p>\n<!-- /wp:paragraph -->";

        if ( str_contains( $content, '<!-- ta-toc-end -->' ) ) {
            $content = str_replace( '<!-- ta-toc-end -->', $entry . "\n\n<!-- ta-toc-end -->", $content );
        } elseif ( str_contains( $content, '<ul class="ta-toc">' ) ) {
            // Legacy list-based ToC: keep existing <li> entries, add new paragraph entries after the list
            $content = preg_replace(
                '/(<ul class="ta-toc">.*?<\/ul>)/s',
                '$1' . "\n\n" . $entry . "\n\n<!-- ta-toc-end -->",
                $content,
                1
            );
        } else {
            // ToC block missing — append fresh at end
            $content .= "\n\n<h2>Table of Contents</h2>\n{$entry}\n\n<!-- ta-toc-end -->";
        }

        $result = wp_update_post( [ 'ID' => $index_id, 'post_content' => $content ], true );
        if ( is_wp_error( $result ) ) {
            error_log( 'TAP: append_toc_entry failed for post ' . $index_id . ': ' . $result->get_error_message() );
        }
    }

    public function update_toc_entry( int $index_id, string $old_url, string $new_title, string $new_url ): void {
        $page = get_post( $index_id );
        if ( ! $page ) return;

        $pattern     = '/<a href="' . preg_quote( esc_url( $old_url ), '/' ) . '">.*?<\/a>/s';
        $replacement = '<a href="' . esc_url( $new_url ) . '">' . esc_html( $new_title ) . '</a>';

        $content = preg_replace_callback(
            $pattern,
            fn() => $replacement,
            $page->post_content,
            1,
            $count
        );
        if ( $count === 0 ) return; // no matching anchor — nothing to fix

        $result = wp_update_post( [ 'ID' => $index_id, 'post_content' => $content ], true );
        if ( is_wp_error( $result ) ) {
            error_log( 'TAP: update_toc_entry failed for post ' . $index_id . ': ' . $result->get_error_message() );
        }
    }

    public function find_or_create_index_page( string $series_slug, string $series_title, string $series_link, int $user_id ): int|WP_Error {
        $existing = get_page_by_path( $series_slug, OBJECT, 'page' );
        if ( $existing ) return $existing->ID;

        $id = wp_insert_post( [
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'post_title'     => $series_title,
            'post_name'      => $series_slug,
            'post_author'    => $user_id,
            'post_content'   => $this->build_index_content( $series_title, $series_link, '' ),
            'comment_status' => 'open',
            'menu_order'     => 0,
        ], true );

        return $id;
    }

    public function handle_synopsis( int $index_page_id, string $series_title, string $series_link, string $chapter_body ): int|WP_Error {
        $content = $this->build_index_content( $series_title, $series_link, $chapter_body );
        $result  = wp_update_post( [
            'ID'           => $index_page_id,
            'post_content' => $content,
        ], true );

        return $result;
    }

    private function build_index_content( string $series_title, string $series_link, string $synopsis_body ): string {
        return '<div style="text-align: center;"><a href="' . esc_url( $series_link ) . '"><strong>' . esc_html( $series_title ) . '</strong></a></div>'
            . "\n\n"
            . $synopsis_body
            . "\n\n<hr />\n\n"
            . "<div>Table of Contents:</div>\n"
            . '<!-- ta-toc-end -->';
    }

    public function convert_to_blocks( string $html, array $images = [], ?array $cover = null, int $post_id = 0 ): string {
        $parts  = preg_split( '/(<p[^>]*>.*?<\/p>)/s', trim( $html ), -1, PREG_SPLIT_DELIM_CAPTURE );
        $blocks = [];

        foreach ( $parts as $part ) {
            $part = trim( $part );
            if ( $part === '' ) continue;

            if ( preg_match( '/^<p[^>]*>.*<\/p>$/s', $part ) ) {
                $blocks[] = "<!-- wp:paragraph -->\n{$part}\n<!-- /wp:paragraph -->";
            } else {
                $blocks[] = "<!-- wp:html -->\n{$part}\n<!-- /wp:html -->";
            }
        }

        $offset = 0;
        foreach ( $images as $image ) {
            $attachment_id = $this->attach_image( $image['filename'], $image['mime'], $image['data_base64'], $post_id );
            if ( is_wp_error( $attachment_id ) ) {
                error_log( 'TAP: attach_image failed for ' . $image['filename'] . ': ' . $attachment_id->get_error_message() );
                continue;
            }
            $idx = max( 0, min( (int) $image['position'] + $offset, count( $blocks ) ) );
            array_splice( $blocks, $idx, 0, [ $this->image_block( $attachment_id ) ] );
            $offset++;
        }

        $body = implode( "\n\n", $blocks );

        $cover_block = '';
        if ( $cover !== null ) {
            $cover_attachment_id = $this->attach_image( $cover['filename'], $cover['mime'], $cover['data_base64'], $post_id );
            if ( is_wp_error( $cover_attachment_id ) ) {
                error_log( 'TAP: attach_image (cover) failed for ' . $cover['filename'] . ': ' . $cover_attachment_id->get_error_message() );
            } else {
                $cover_block = $this->image_block( $cover_attachment_id ) . "\n\n";
            }
        }

        return $cover_block . self::NAV_BLOCK . "\n\n" . self::SEPARATOR . "\n\n" . $body . "\n\n" . self::SEPARATOR . "\n\n" . self::NAV_BLOCK;
    }

    private function image_block( int $attachment_id ): string {
        $url = esc_url( wp_get_attachment_url( $attachment_id ) );
        return "<!-- wp:image {\"id\":{$attachment_id}} -->\n"
            . "<figure class=\"wp-block-image\"><img src=\"{$url}\" class=\"wp-image-{$attachment_id}\" alt=\"\"/></figure>\n"
            . "<!-- /wp:image -->";
    }
}
