<?php

if ( ! defined( 'ABSPATH' ) ) exit;

class TAP_Publisher {

    public const LEGACY_NAV_BLOCK = "<!-- wp:html -->\n<div id=\"textbox\">\n<p class=\"alignleft\">[previous_page]</p>\n<p class=\"alignright\">[next_page]</p>\n</div>\n<!-- /wp:html -->";
    private const SEPARATOR = "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->";

    public function publish( array $data, int $user_id ): array|WP_Error {
        $series_slug  = sanitize_title( $data['series_slug'] );
        $chapter_idx  = (int) $data['chapter_index'];
        // Optional since 1.5.3 — EPUB-imported series carry no source URL.
        $data['series_link'] = isset( $data['series_link'] ) && is_string( $data['series_link'] )
            ? $data['series_link'] : '';

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
                'updated'  => $already_has_content,
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
            $old_url      = get_permalink( $existing->ID ); // capture before any rename
            $volume_title = isset( $data['volume_title'] ) && is_string( $data['volume_title'] ) ? $data['volume_title'] : '';

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
            $this->write_toc( $index_id, $series_slug, [
                'href'          => $new_url,
                'text'          => $data['chapter_title'],
                'volume_title'  => $volume_title,
                'index'         => $chapter_idx,
                'illustrations' => false,
                'anchor_index'  => $chapter_idx,
            ], $old_url );

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

        $chapter_url  = get_permalink( $chapter_id );
        $volume_title = isset( $data['volume_title'] ) && is_string( $data['volume_title'] ) ? $data['volume_title'] : '';
        $this->write_toc( $index_id, $series_slug, [
            'href'          => $chapter_url,
            'text'          => $data['chapter_title'],
            'volume_title'  => $volume_title,
            'index'         => $chapter_idx,
            'illustrations' => false,
            'anchor_index'  => $chapter_idx,
        ] );

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

    public function publish_illustrations( array $data, int $user_id ): array|WP_Error {
        $series_slug  = sanitize_title( $data['series_slug'] );
        $series_link  = isset( $data['series_link'] ) && is_string( $data['series_link'] ) ? $data['series_link'] : '';
        $volume_title = isset( $data['volume_title'] ) && is_string( $data['volume_title'] ) ? $data['volume_title'] : '';
        $mode         = ( $data['mode'] ?? 'replace' ) === 'append' ? 'append' : 'replace';
        $cover        = isset( $data['cover'] ) && is_array( $data['cover'] ) ? $data['cover'] : null;

        $index_id = $this->find_or_create_index_page(
            $series_slug, $data['series_title'], $series_link, $user_id
        );
        if ( is_wp_error( $index_id ) ) return $index_id;

        $slug = $volume_title !== ''
              ? "{$series_slug}-illustrations-" . sanitize_title( $volume_title )
              : "{$series_slug}-illustrations";
        $existing = get_page_by_path( "{$series_slug}/{$slug}", OBJECT, 'page' );

        if ( $mode === 'append' ) {
            if ( ! $existing ) {
                return new WP_Error( 'no_gallery', 'append batch received before replace batch' );
            }
            $blocks = $this->build_illustrations_blocks( $data['images'], null, $existing->ID );
            $update = wp_update_post( [
                'ID'           => $existing->ID,
                'post_content' => wp_slash( rtrim( $existing->post_content ) . "\n\n" . $blocks ),
            ], true );
            if ( is_wp_error( $update ) ) return $update;
            return [
                'status'   => 'ok',
                'page_url' => get_permalink( $existing->ID ),
                'created'  => false,
                'updated'  => true,
            ];
        }

        // mode === 'replace'
        $title       = $volume_title !== ''
               ? "{$volume_title} Illustrations"
               : $data['series_title_short'] . ' Illustrations';
        $first_index = isset( $data['first_chapter_index'] ) ? (int) $data['first_chapter_index'] : null;
        $created     = false;

        if ( $existing ) {
            $gallery_id  = $existing->ID;
            $attachments = get_posts( [
                'post_type'   => 'attachment',
                'post_parent' => $gallery_id,
                'numberposts' => -1,
                'post_status' => 'any',
            ] );
            foreach ( $attachments as $attachment ) {
                wp_delete_attachment( $attachment->ID, true );
            }
        } else {
            $gallery_id = wp_insert_post( [
                'post_type'      => 'page',
                'post_title'     => wp_slash( $title ),
                'post_name'      => $slug,
                'post_parent'    => $index_id,
                'post_status'    => 'publish',
                'post_author'    => $user_id,
                'post_content'   => '',
                'comment_status' => 'closed',
                'menu_order'     => null !== $first_index ? $first_index : 0,
            ], true );
            if ( is_wp_error( $gallery_id ) ) return $gallery_id;
            $created = true;
        }

        $blocks = $this->build_illustrations_blocks( $data['images'], $cover, $gallery_id );
        $args   = [
            'ID'           => $gallery_id,
            'post_title'   => wp_slash( $title ),
            'post_content' => wp_slash( $blocks ),
        ];
        if ( null !== $first_index ) {
            $args['menu_order'] = $first_index;
        }
        $args   = $this->apply_schedule( $args, $data, true );
        $update = wp_update_post( $args, true );
        if ( is_wp_error( $update ) ) {
            if ( $created ) wp_delete_post( $gallery_id, true );
            return $update;
        }

        // Upsert on every replace: it de-dupes, moves the link under its
        // volume heading and re-adds a link that was removed by hand.
        $this->write_toc( $index_id, $series_slug, [
            'href'          => get_permalink( $gallery_id ),
            'text'          => 'Illustrations',
            'volume_title'  => $volume_title,
            'index'         => null,
            'illustrations' => true,
            'anchor_index'  => $first_index,
        ] );

        return [
            'status'   => 'ok',
            'page_url' => get_permalink( $gallery_id ),
            'created'  => $created,
            'updated'  => ! $created,
        ];
    }

    private function find_existing_teaser_post( array $data ): int {
        $chapter_idx = (int) $data['chapter_index'];

        // A 1.5.5 chapter is titled "TS Chapter N"; a labelled one from 1.6.0
        // is titled "TS {label}"; this version writes "TS {volume} - {title}"
        // (or "TS - {title}"). Look for all shapes the same chapter may be
        // titled with, but only adopt an unambiguous single match overall.
        $titles = [
            $data['series_title_short'] . ' Chapter ' . $chapter_idx,
            // A teaser adopted before the chapter gained a volume keeps the
            // no-volume form, so that one is always looked for.
            $data['series_title_short'] . ' - ' . $this->chapter_title_text( $data ),
        ];
        $label = $this->label( $data );
        if ( null !== $label ) {
            $titles[] = $data['series_title_short'] . ' ' . $label;
            $titles[] = $this->display_title( $data );
        }

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;
        if ( ! $child_cat ) return 0;

        $found = [];
        foreach ( array_unique( $titles ) as $title ) {
            $matches = get_posts( [
                'post_type'   => 'post',
                'post_status' => [ 'publish', 'draft', 'future', 'pending', 'private' ],
                'title'       => $title,
                'category'    => $child_cat,
                'numberposts' => 2,
                'fields'      => 'ids',
            ] );
            foreach ( $matches as $id ) {
                $found[ (int) $id ] = (int) $id;
            }
        }

        // Exactly one unambiguous match is adoptable. Zero means it really was
        // never created; two or more means we cannot tell which is canonical,
        // and guessing would compound the very duplication this prevents.
        return count( $found ) === 1 ? (int) array_values( $found )[0] : 0;
    }

    private function label( array $data ): ?string {
        if ( isset( $data['chapter_label'] ) && is_string( $data['chapter_label'] ) ) {
            $label = trim( $data['chapter_label'] );
            if ( '' !== $label ) return $label;
        }
        return null;
    }

    /**
     * The volume to show in reader-facing titles. ta-web sends chapter_label
     * only for a chapter that sits in a volume, so its presence is the signal
     * that volume_title belongs in the title; without it the titles carry no
     * volume (a syosetu series keeps its own chapter numbering in the ToC).
     */
    private function title_volume( array $data ): string {
        if ( null === $this->label( $data ) ) return '';
        return isset( $data['volume_title'] ) && is_string( $data['volume_title'] )
            ? trim( $data['volume_title'] )
            : '';
    }

    /**
     * The chapter's own title, or a numbered fallback when the payload holds
     * none (the route requires one, but a chapter_title consisting only of the
     * series prefix is stripped to nothing in publish()).
     */
    private function chapter_title_text( array $data ): string {
        $title = isset( $data['chapter_title'] ) && is_string( $data['chapter_title'] )
            ? trim( $data['chapter_title'] )
            : '';
        return '' !== $title ? $title : 'Chapter ' . (int) $data['chapter_index'];
    }

    /**
     * Reader-facing title for the chapter page and its teaser post:
     * "{series} {volume} - {chapter_title}", or "{series} - {chapter_title}"
     * without a volume. Never the series-wide chapter number or the label.
     */
    private function display_title( array $data ): string {
        $title  = (string) $data['series_title_short'];
        $volume = $this->title_volume( $data );
        if ( '' !== $volume ) {
            $title .= ' ' . $volume;
        }
        return $title . ' - ' . $this->chapter_title_text( $data );
    }

    private function read_link_text( array $data ): string {
        return 'Read ' . $this->chapter_title_text( $data );
    }

    private function reader_page_title( array $data ): string {
        return $this->display_title( $data );
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
        $title   = $this->display_title( $data );
        $read    = $this->read_link_text( $data );
        $content = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                 . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">' . esc_html( $read ) . '</a></p>';

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;

        $post_args = [
            'post_type'     => 'post',
            'post_title'    => wp_slash( $title ),
            'post_author'   => $user_id,
            'post_content'  => wp_slash( $content ),
            'post_category' => $child_cat ? [ $child_cat ] : [],
        ];

        $post_args = $this->apply_schedule( $post_args, $data );

        return wp_insert_post( $post_args, true );
    }

    public function update_post( int $post_id, array $data, string $chapter_url ): int|WP_Error {
        $title = $this->display_title( $data );
        $read  = $this->read_link_text( $data );

        // The teaser body is wholly derived from the payload — there is no
        // human-authored content to preserve — so rebuild it exactly the way
        // create_post does rather than patching one line of it.
        $content = '<p>' . esc_html( $data['first_line'] ) . '</p>'
                 . "\n<p><a href=\"" . esc_url( $chapter_url ) . '">' . esc_html( $read ) . '</a></p>';

        $parent_cat = $this->get_or_create_category( 'Web Novel Translation' );
        $child_cat  = $parent_cat ? $this->get_or_create_category( $data['series_title_short'], $parent_cat ) : 0;

        $post_args = [
            'ID'            => $post_id,
            'post_title'    => wp_slash( $title ),
            'post_content'  => wp_slash( $content ),
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

    public function get_illustrations_status( string $series_slug, string $volume_title ): array {
        $slug = '' !== $volume_title
            ? "{$series_slug}-illustrations-" . sanitize_title( $volume_title )
            : "{$series_slug}-illustrations";
        $page = get_page_by_path( "{$series_slug}/{$slug}", OBJECT, 'page' );

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
            'post_title'     => wp_slash( $this->reader_page_title( $data ) ),
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
        $update_result = wp_update_post( [ 'ID' => $chapter_id, 'post_content' => wp_slash( $content ) ], true );
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
            'post_title'   => wp_slash( $this->reader_page_title( $data ) ),
            'post_content' => wp_slash( $content ),
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

    /**
     * Read the index page, let TAP_Toc place or move one entry, and save only
     * when the content actually changed.
     */
    private function write_toc( int $index_id, string $series_slug, array $entry, ?string $old_href = null ): void {
        $page = get_post( $index_id );
        if ( ! $page ) return;

        $resolve = function ( string $href ) use ( $index_id ): int {
            $id = url_to_postid( $href );
            if ( ! $id ) return 0;
            $post = get_post( $id );
            return $post && (int) $post->post_parent === $index_id ? $id : 0;
        };

        $content = TAP_Toc::upsert( $page->post_content, $series_slug, $entry, $old_href, $resolve );
        if ( $content === $page->post_content ) return;

        $result = wp_update_post( [ 'ID' => $index_id, 'post_content' => wp_slash( $content ) ], true );
        if ( is_wp_error( $result ) ) {
            error_log( 'TAP: write_toc failed for post ' . $index_id . ': ' . $result->get_error_message() );
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
            'post_content'   => wp_slash( $this->build_index_content( $series_title, $series_link, '' ) ),
            'comment_status' => 'open',
            'menu_order'     => 0,
        ], true );

        return $id;
    }

    public function handle_synopsis( int $index_page_id, string $series_title, string $series_link, string $chapter_body ): int|WP_Error {
        $page = get_post( $index_page_id );
        if ( ! $page ) {
            return new WP_Error( 'page_missing', 'Index page not found' );
        }

        $content = TAP_Toc::replace_head( $page->post_content, $this->build_index_head( $series_title, $series_link, $chapter_body ) );

        $result = wp_update_post( [
            'ID'           => $index_page_id,
            'post_content' => wp_slash( $content ),
        ], true );

        return $result;
    }

    /**
     * build_index_content() cut just before its <div>Table of Contents:</div>:
     * title heading, synopsis body and <hr /> only.
     */
    private function build_index_head( string $series_title, string $series_link, string $synopsis_body ): string {
        $marker = '<div>Table of Contents:</div>';
        $full   = $this->build_index_content( $series_title, $series_link, $synopsis_body );
        $pos    = strpos( $full, $marker );
        return false === $pos ? $full : substr( $full, 0, $pos );
    }

    private function build_index_content( string $series_title, string $series_link, string $synopsis_body ): string {
        $heading = '' === $series_link
            ? '<strong>' . esc_html( $series_title ) . '</strong>'
            : '<a href="' . esc_url( $series_link ) . '"><strong>' . esc_html( $series_title ) . '</strong></a>';
        return '<div style="text-align: center;">' . $heading . '</div>'
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

        return $cover_block . self::SEPARATOR . "\n\n" . $body . "\n\n" . self::SEPARATOR;
    }

    private function build_illustrations_blocks( array $images, ?array $cover, int $post_id ): string {
        $blocks = [];

        if ( $cover !== null ) {
            $cid = $this->attach_image( $cover['filename'], $cover['mime'], $cover['data_base64'], $post_id );
            if ( is_wp_error( $cid ) ) {
                error_log( 'TAP: attach_image (illus cover) failed: ' . $cid->get_error_message() );
            } else {
                $blocks[] = $this->image_block( $cid );
            }
        }

        foreach ( $images as $image ) {
            $aid = $this->attach_image( $image['filename'], $image['mime'], $image['data_base64'], $post_id );
            if ( is_wp_error( $aid ) ) {
                error_log( 'TAP: attach_image (illus) failed for ' . $image['filename'] . ': ' . $aid->get_error_message() );
                continue;
            }
            $blocks[] = $this->image_block( $aid );
        }

        return implode( "\n\n", $blocks );
    }

    private function image_block( int $attachment_id ): string {
        $url = esc_url( wp_get_attachment_url( $attachment_id ) );
        return "<!-- wp:image {\"id\":{$attachment_id}} -->\n"
            . "<figure class=\"wp-block-image\"><img src=\"{$url}\" class=\"wp-image-{$attachment_id}\" alt=\"\"/></figure>\n"
            . "<!-- /wp:image -->";
    }
}
