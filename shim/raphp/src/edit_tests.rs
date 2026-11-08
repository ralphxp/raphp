//! Editing one line (plan P3.11b): the caret, the selection and the keys, on grapheme clusters and words.

use crate::edit::Editor;

fn at(text: &str, caret: usize, anchor: usize) -> Editor {
    Editor {
        text: text.to_owned(),
        caret,
        anchor,
    }
}

#[test]
fn a_new_editor_has_the_caret_after_the_text_and_nothing_selected() {
    let editor = Editor::new("héllo");

    assert_eq!(
        (editor.caret, editor.anchor),
        (6, 6),
        "six bytes: the é is two"
    );
    assert!(!editor.has_selection());
    assert_eq!(editor.selected(), "");
}

#[test]
fn typing_inserts_at_the_caret_and_replaces_a_selection() {
    let mut editor = at("hello world", 5, 5);
    assert!(editor.insert(",", 100));
    assert_eq!((editor.text.as_str(), editor.caret), ("hello, world", 6));

    let mut editor = at("hello world", 5, 0); // "hello" selected
    assert!(editor.insert("bye", 100));
    assert_eq!(
        (editor.text.as_str(), editor.caret, editor.anchor),
        ("bye world", 3, 3)
    );

    assert!(
        !Editor::new("x").insert("", 100),
        "inserting nothing changes nothing"
    );
    assert!(
        !Editor::new("x").insert("\n\t\u{7f}", 100),
        "and neither does inserting only control characters"
    );
}

#[test]
fn line_breaks_and_control_characters_are_dropped_from_what_is_typed_or_pasted() {
    let mut editor = Editor::new("");

    editor.insert("one\r\ntwo\tthree\u{0}", 100);

    assert_eq!(editor.text, "onetwothree");
}

#[test]
fn the_text_never_grows_past_its_limit_and_is_cut_on_a_character() {
    let mut editor = Editor::new("abc");

    assert!(editor.insert("defgh", 6));
    assert_eq!(editor.text, "abcdef", "cut to fit");
    assert!(!editor.insert("x", 6), "no room left");

    let mut editor = Editor::new("ab");
    editor.insert("€€", 5); // 3 bytes each: only one fits in the 3 bytes left
    assert_eq!(editor.text, "ab€");

    let mut editor = at("abcdef", 5, 0); // a selection gives room back
    assert!(editor.insert("XYZ", 6));
    assert_eq!(editor.text, "XYZf");
}

#[test]
fn the_caret_moves_by_a_whole_grapheme_cluster() {
    // "e" with a combining acute (3 bytes), a flag (two regional indicators, 8 bytes) and a family emoji (25 bytes).
    let text = "e\u{301}\u{1F1F3}\u{1F1EC}\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
    let mut editor = Editor::new(text);

    editor.move_left(false, false);
    assert_eq!(editor.caret, 3 + 8, "the family is one step");
    editor.move_left(false, false);
    assert_eq!(editor.caret, 3, "the flag is one step");
    editor.move_left(false, false);
    assert_eq!(editor.caret, 0, "e and its accent are one step");
    editor.move_left(false, false);
    assert_eq!(editor.caret, 0, "and the start is the start");

    editor.move_right(false, false);
    assert_eq!(editor.caret, 3);
    editor.move_right(false, false);
    editor.move_right(false, false);
    editor.move_right(false, false);
    assert_eq!(editor.caret, text.len());
}

#[test]
fn backspace_and_delete_remove_one_grapheme_cluster_not_one_byte_or_one_scalar() {
    let mut editor = Editor::new("ae\u{301}");
    assert!(editor.backspace(false));
    assert_eq!(editor.text, "a", "the accented e went in one press");

    let mut editor = at("a\u{1F468}\u{200D}\u{1F469}b", 1, 1);
    assert!(editor.delete(false));
    assert_eq!(editor.text, "ab", "the two-person emoji went in one press");

    assert!(!Editor::new("").backspace(false));
    assert!(!at("x", 0, 0).backspace(false), "nothing before the start");
    assert!(!Editor::new("x").delete(false), "nothing after the end");
}

#[test]
fn backspace_and_delete_remove_the_selection_if_there_is_one() {
    let mut editor = at("hello world", 8, 3); // "lo wo"
    assert!(editor.backspace(false));
    assert_eq!(
        (editor.text.as_str(), editor.caret, editor.anchor),
        ("helrld", 3, 3)
    );

    let mut editor = at("hello world", 3, 8);
    assert!(editor.delete(false));
    assert_eq!((editor.text.as_str(), editor.caret), ("helrld", 3));
}

#[test]
fn control_with_the_arrows_and_the_delete_keys_works_by_word() {
    let mut editor = Editor::new("one two  three");

    editor.move_left(false, true);
    assert_eq!(editor.caret, 9, "the start of three");
    editor.move_left(false, true);
    assert_eq!(editor.caret, 4, "the start of two, over the two spaces");
    editor.move_right(false, true);
    assert_eq!(editor.caret, 7, "the end of two");
    editor.move_right(false, true);
    assert_eq!(editor.caret, 14, "the end of three, over the spaces");

    let mut editor = Editor::new("one two  three");
    assert!(editor.backspace(true));
    assert_eq!(editor.text, "one two  ", "the word before the caret");
    assert!(editor.backspace(true));
    assert_eq!(editor.text, "one ");

    let mut editor = at("one two three", 4, 4);
    assert!(editor.delete(true));
    assert_eq!(editor.text, "one  three", "the word after the caret");
}

#[test]
fn shift_extends_the_selection_and_moving_without_it_collapses_it_to_the_near_end() {
    let mut editor = at("abcdef", 2, 2);
    editor.move_right(true, false);
    editor.move_right(true, false);
    assert_eq!(
        (editor.caret, editor.anchor, editor.selected()),
        (4, 2, "cd")
    );

    editor.move_left(true, false);
    assert_eq!(
        (editor.caret, editor.anchor, editor.selected()),
        (3, 2, "c")
    );

    editor.move_left(false, false);
    assert_eq!(
        (editor.caret, editor.anchor),
        (2, 2),
        "left with a selection goes to its start"
    );

    let mut editor = at("abcdef", 4, 2);
    editor.move_right(false, false);
    assert_eq!(
        (editor.caret, editor.anchor),
        (4, 4),
        "right goes to its end"
    );
}

#[test]
fn home_end_and_select_all() {
    let mut editor = at("abc def", 3, 3);

    editor.home(false);
    assert_eq!((editor.caret, editor.anchor), (0, 0));
    editor.end(true);
    assert_eq!(
        (editor.caret, editor.anchor, editor.selected()),
        (7, 0, "abc def"),
        "shift end selects to the end"
    );
    editor.home(true);
    assert_eq!((editor.caret, editor.anchor), (0, 0));

    editor.select_all();
    assert_eq!((editor.caret, editor.anchor), (7, 0));
    assert_eq!(editor.selected(), "abc def");
}

#[test]
fn clicking_puts_the_caret_between_clusters_and_shift_or_drag_extends_from_the_anchor() {
    let mut editor = at("ae\u{301}b", 0, 0);

    editor.set_caret(2, false); // inside the accented e's two scalars: moved back to its start
    assert_eq!((editor.caret, editor.anchor), (1, 1));
    editor.set_caret(100, true); // past the end: the end, with the anchor kept
    assert_eq!((editor.caret, editor.anchor), (editor.text.len(), 1));
}

#[test]
fn a_double_click_selects_the_word_under_it_and_a_gap_is_just_a_caret() {
    let mut editor = Editor::new("one two  three");

    editor.select_word_at(5);
    assert_eq!(editor.selected(), "two");

    editor.select_word_at(3); // just after "one": the word before it
    assert_eq!(editor.selected(), "one");

    editor.select_word_at(8); // between two spaces
    assert!(!editor.has_selection());
    assert_eq!(editor.caret, 8);
}

#[test]
fn cut_returns_the_selection_and_removes_it() {
    let mut editor = at("hello world", 5, 0);

    assert_eq!(editor.cut().as_deref(), Some("hello"));
    assert_eq!((editor.text.as_str(), editor.caret), (" world", 0));
    assert_eq!(editor.cut(), None, "nothing selected, nothing cut");
}

#[test]
fn a_text_changed_from_outside_gets_the_caret_back_in_a_valid_place() {
    let mut editor = at("hello world", 11, 5);
    editor.text = "hé".to_owned(); // shorter: 3 bytes
    editor.caret = 11;
    editor.anchor = 2; // inside the é

    editor.clamp();

    assert_eq!(
        (editor.caret, editor.anchor),
        (3, 1),
        "the end, and the start of the é"
    );
}

#[test]
fn an_inserted_combining_mark_joins_the_cluster_before_it() {
    let mut editor = at("ab", 1, 1);

    editor.insert("\u{301}", 100); // an acute accent: it goes on the a

    assert_eq!(editor.text, "a\u{301}b");
    assert_eq!(editor.caret, 3, "after the accent, which is before the b");
}

#[test]
fn an_underscore_is_part_of_a_word_for_the_jumps() {
    let mut editor = Editor::new("foo_bar baz");

    editor.move_left(false, true);
    assert_eq!(editor.caret, 8, "the start of baz");
    editor.move_left(false, true);
    assert_eq!(editor.caret, 0, "foo_bar is one word");
    editor.move_right(false, true);
    assert_eq!(editor.caret, 7, "and its end is after the r");
}
