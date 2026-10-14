<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The repo page FAQ — five questions: two fixed ones built at render time
 * ("Как установить X?" from install_command, "Какие задачи решает X?" from the
 * LLM's tasks_answer) and up to three the LLM wrote for this repo
 * (`_gh_faq_json`). One source for both the visible section (lagr/repo-content)
 * and the FAQPage JSON-LD (LAGR_Schema) — search engines require the marked-up
 * questions to be the ones on the page, word for word.
 */
class LAGR_FAQ {

	/**
	 * @return array<int, array{question:string, answer_html:string, answer_text:string}>
	 */
	public static function items( $post_id ) {
		$name  = self::repo_name( $post_id );
		$items = array();

		$install = self::install_command( $post_id );
		if ( '' !== $install['command'] ) {
			$hint    = $install['is_clone']
				? 'Отдельной команды установки в README нет — склонируйте репозиторий и следуйте инструкции из README:'
				: 'Основная команда установки из README:';
			$items[] = array(
				'question'    => 'Как установить ' . $name . '?',
				'answer_html' => '<div class="lagr-install">'
					. '<p class="lagr-install-hint">' . esc_html( $hint ) . '</p>'
					. '<div class="lagr-install-cmd"><code>' . esc_html( $install['command'] ) . '</code>'
					. '<button type="button" class="lagr-copy" data-copy="' . esc_attr( $install['command'] ) . '">Копировать</button></div>'
					. '</div>',
				'answer_text' => $hint . ' ' . $install['command'],
			);
		}

		// Repos collected before the FAQ existed have no tasks_answer — the
		// short description answers the same question well enough.
		$tasks = trim( (string) get_post_meta( $post_id, '_gh_faq_tasks', true ) );
		if ( '' === $tasks ) {
			$tasks = trim( (string) get_post_meta( $post_id, 'short_description', true ) );
		}
		if ( '' !== $tasks ) {
			$items[] = self::text_item( 'Какие задачи решает ' . $name . '?', $tasks );
		}

		$generated = json_decode( (string) get_post_meta( $post_id, '_gh_faq_json', true ), true );
		foreach ( is_array( $generated ) ? $generated : array() as $qa ) {
			if ( ! empty( $qa['question'] ) && ! empty( $qa['answer'] ) ) {
				$items[] = self::text_item( (string) $qa['question'], (string) $qa['answer'] );
			}
		}

		return array_slice( $items, 0, 5 );
	}

	/** Repo part of owner/repo — reads better in a question than the full slug. */
	public static function repo_name( $post_id ) {
		$full = (string) get_post_meta( $post_id, '_gh_full_name', true );
		if ( '' === $full ) {
			$full = get_the_title( $post_id );
		}
		$parts = explode( '/', $full );
		return (string) end( $parts );
	}

	/**
	 * Explicit install command (LLM-extracted from the README or set by an
	 * editor); falls back to a git clone, which is valid for any repo.
	 *
	 * @return array{command:string, is_clone:bool}
	 */
	public static function install_command( $post_id ) {
		$command = trim( (string) get_post_meta( $post_id, 'install_command', true ) );
		if ( '' !== $command ) {
			return array(
				'command'  => $command,
				'is_clone' => false,
			);
		}
		$full = (string) get_post_meta( $post_id, '_gh_full_name', true );
		return array(
			'command'  => '' !== $full ? 'git clone https://github.com/' . $full . '.git' : '',
			'is_clone' => true,
		);
	}

	private static function text_item( $question, $answer ) {
		return array(
			'question'    => $question,
			'answer_html' => wpautop( esc_html( $answer ) ),
			'answer_text' => $answer,
		);
	}
}
