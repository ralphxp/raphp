//! Editing one line of text (plan P3.11b): a caret, a selection, and the keys that move and change them.
//!
//! Pure: no window, no model, no clipboard. Positions are byte indexes into the text that always stand **between
//! grapheme clusters** (what a person sees as one character, so an "e" with an accent, a flag or a family of emoji
//! is one step for the caret and one press of Backspace), and the text stays valid UTF-8 whatever is done to it.
//! The selection is the text between the caret and the anchor, whichever comes first.
//!
//! A text input is one line: line breaks and other control characters are dropped from what is typed or pasted.

use unicode_segmentation::UnicodeSegmentation;

#[derive(Clone, Debug, Default, PartialEq, Eq)]
pub(crate) struct Editor {
    pub text: String,
    /// Where the caret is, between two grapheme clusters (or at an end).
    pub caret: usize,
    /// The other end of the selection; the same as the caret when nothing is selected.
    pub anchor: usize,
}

/// A character of a word: what a jump by word stops at the edges of.
fn is_word(c: char) -> bool {
    c.is_alphanumeric() || c == '_'
}

impl Editor {
    /// A text with the caret after it, nothing selected.
    #[cfg(test)]
    pub fn new(text: &str) -> Self {
        Self {
            text: text.to_owned(),
            caret: text.len(),
            anchor: text.len(),
        }
    }

    /// The selection as `(start, end)`, equal when there is none.
    pub fn selection(&self) -> (usize, usize) {
        (self.caret.min(self.anchor), self.caret.max(self.anchor))
    }

    pub fn has_selection(&self) -> bool {
        self.caret != self.anchor
    }

    pub fn selected(&self) -> &str {
        let (start, end) = self.selection();

        &self.text[start..end]
    }

    /// Puts the caret and the anchor back at valid places after the text was changed from outside (PHP set it).
    pub fn clamp(&mut self) {
        self.caret = self.floor(self.caret);
        self.anchor = self.floor(self.anchor);
    }

    /// The nearest place between graphemes at or before an index.
    fn floor(&self, index: usize) -> usize {
        let index = index.min(self.text.len());
        let mut at = 0;
        for (start, grapheme) in self.text.grapheme_indices(true) {
            if start + grapheme.len() > index {
                return if start <= index { start } else { at };
            }
            at = start + grapheme.len();
        }

        at.max(index.min(self.text.len()).min(at))
    }

    fn previous(&self, index: usize) -> usize {
        self.text[..index]
            .grapheme_indices(true)
            .next_back()
            .map_or(0, |(start, _)| start)
    }

    fn next(&self, index: usize) -> usize {
        self.text[index..]
            .graphemes(true)
            .next()
            .map_or(self.text.len(), |grapheme| index + grapheme.len())
    }

    /// The start of the word at or before an index, skipping what is between words first.
    fn word_before(&self, index: usize) -> usize {
        let mut at = index;
        while let Some(c) = self.text[..at].chars().next_back() {
            if is_word(c) {
                break;
            }
            at -= c.len_utf8();
        }
        while let Some(c) = self.text[..at].chars().next_back() {
            if !is_word(c) {
                break;
            }
            at -= c.len_utf8();
        }

        self.floor(at)
    }

    /// The end of the word at or after an index, skipping what is between words first.
    fn word_after(&self, index: usize) -> usize {
        let mut at = index;
        while let Some(c) = self.text[at..].chars().next() {
            if is_word(c) {
                break;
            }
            at += c.len_utf8();
        }
        while let Some(c) = self.text[at..].chars().next() {
            if !is_word(c) {
                break;
            }
            at += c.len_utf8();
        }

        self.floor(at)
    }

    fn move_to(&mut self, index: usize, extend: bool) {
        self.caret = index;
        if !extend {
            self.anchor = index;
        }
    }

    /// Left one grapheme (or one word). With a selection and no shift, the caret goes to the start of it.
    pub fn move_left(&mut self, extend: bool, by_word: bool) {
        if self.has_selection() && !extend {
            self.move_to(self.selection().0, false);

            return;
        }

        let to = if by_word {
            self.word_before(self.caret)
        } else {
            self.previous(self.caret)
        };
        self.move_to(to, extend);
    }

    /// Right one grapheme (or one word). With a selection and no shift, the caret goes to the end of it.
    pub fn move_right(&mut self, extend: bool, by_word: bool) {
        if self.has_selection() && !extend {
            self.move_to(self.selection().1, false);

            return;
        }

        let to = if by_word {
            self.word_after(self.caret)
        } else {
            self.next(self.caret)
        };
        self.move_to(to, extend);
    }

    pub fn home(&mut self, extend: bool) {
        self.move_to(0, extend);
    }

    pub fn end(&mut self, extend: bool) {
        self.move_to(self.text.len(), extend);
    }

    pub fn select_all(&mut self) {
        self.anchor = 0;
        self.caret = self.text.len();
    }

    /// Puts the caret at an index (made valid), keeping the anchor when extending a selection.
    pub fn set_caret(&mut self, index: usize, extend: bool) {
        let at = self.floor(index);
        self.move_to(at, extend);
    }

    /// Selects the word around an index (a double click), or the stretch of what is between words.
    pub fn select_word_at(&mut self, index: usize) {
        let index = self.floor(index);
        let word_char = self.text[index..].chars().next().is_some_and(is_word)
            || self.text[..index].chars().next_back().is_some_and(is_word);
        if !word_char {
            self.set_caret(index, false);

            return;
        }

        let mut start = index;
        while let Some(c) = self.text[..start].chars().next_back() {
            if !is_word(c) {
                break;
            }
            start -= c.len_utf8();
        }
        let mut end = index;
        while let Some(c) = self.text[end..].chars().next() {
            if !is_word(c) {
                break;
            }
            end += c.len_utf8();
        }
        self.anchor = self.floor(start);
        self.caret = self.floor(end);
    }

    /// Replaces the selection (or inserts at the caret) with typed or pasted text, from which control characters
    /// are dropped, cut short so that the whole text stays within `max_bytes`. True if the text changed.
    pub fn insert(&mut self, typed: &str, max_bytes: usize) -> bool {
        let cleaned: String = typed.chars().filter(|c| !c.is_control()).collect();
        let (start, end) = self.selection();
        let room = max_bytes.saturating_sub(self.text.len() - (end - start));
        let mut take = cleaned.len().min(room);
        while !cleaned.is_char_boundary(take) {
            take -= 1;
        }
        let cleaned = &cleaned[..take];
        if cleaned.is_empty() && start == end {
            return false;
        }

        self.text.replace_range(start..end, cleaned);
        self.caret = start + cleaned.len();
        self.anchor = self.caret;
        self.clamp(); // an inserted combining mark may join the grapheme before it

        true
    }

    /// Deletes the selection, or the grapheme (or word) before the caret. True if the text changed.
    pub fn backspace(&mut self, by_word: bool) -> bool {
        if !self.has_selection() {
            if self.caret == 0 {
                return false;
            }
            let from = if by_word {
                self.word_before(self.caret)
            } else {
                self.previous(self.caret)
            };
            self.anchor = from;
        }

        self.delete_selection()
    }

    /// Deletes the selection, or the grapheme (or word) after the caret. True if the text changed.
    pub fn delete(&mut self, by_word: bool) -> bool {
        if !self.has_selection() {
            if self.caret == self.text.len() {
                return false;
            }
            let to = if by_word {
                self.word_after(self.caret)
            } else {
                self.next(self.caret)
            };
            self.anchor = to;
        }

        self.delete_selection()
    }

    fn delete_selection(&mut self) -> bool {
        let (start, end) = self.selection();
        if start == end {
            return false;
        }

        self.text.replace_range(start..end, "");
        self.caret = start;
        self.anchor = start;

        true
    }

    /// Removes the selection and returns it (cut), or nothing when none is selected.
    pub fn cut(&mut self) -> Option<String> {
        if !self.has_selection() {
            return None;
        }

        let taken = self.selected().to_owned();
        self.delete_selection();

        Some(taken)
    }
}
