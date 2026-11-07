//! Development tasks for the shim. `cargo run -p xtask -- header` writes `include/raphp.h`;
//! `cargo run -p xtask -- header --check` fails if the committed file is not what the sources produce.

use std::path::PathBuf;
use std::process::ExitCode;

fn main() -> ExitCode {
    let arguments: Vec<String> = std::env::args().skip(1).collect();
    let check = arguments.iter().any(|argument| argument == "--check");

    if arguments.first().map(String::as_str) != Some("header") {
        eprintln!("usage: xtask header [--check]");

        return ExitCode::from(2);
    }

    let root = PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("..");
    let header_path = root.join("include/raphp.h");
    let config = match cbindgen::Config::from_file(root.join("cbindgen.toml")) {
        Ok(config) => config,
        Err(error) => {
            eprintln!("cannot read cbindgen.toml: {error}");

            return ExitCode::FAILURE;
        }
    };
    let bindings = match cbindgen::Builder::new()
        .with_crate(root.join("raphp"))
        .with_config(config)
        .generate()
    {
        Ok(bindings) => bindings,
        Err(error) => {
            eprintln!("cbindgen failed: {error}");

            return ExitCode::FAILURE;
        }
    };

    let mut raw = Vec::new();
    bindings.write(&mut raw);
    let generated = match ffi_clean(&String::from_utf8_lossy(&raw)) {
        Ok(text) => text.into_bytes(),
        Err(problem) => {
            eprintln!("header: {problem}");

            return ExitCode::FAILURE;
        }
    };

    if check {
        let committed = std::fs::read(&header_path).unwrap_or_default();
        if committed == generated {
            println!("header: include/raphp.h is up to date");

            return ExitCode::SUCCESS;
        }

        eprintln!(
            "header: include/raphp.h differs from what the sources produce; run `make header` and commit it"
        );

        return ExitCode::FAILURE;
    }

    if let Err(error) = std::fs::write(&header_path, &generated) {
        eprintln!("cannot write {}: {error}", header_path.display());

        return ExitCode::FAILURE;
    }

    println!("header: wrote {}", header_path.display());

    ExitCode::SUCCESS
}

/// PHP's `FFI::cdef()` understands declarations only. cbindgen's C output has a C23 branch around the
/// enum's underlying type (`#if __STDC_VERSION__ >= 202311L ... #else ... #endif`); this keeps the
/// pre-C23 branch and drops the directives. Any other preprocessor line is an error, because FFI could
/// not read it either.
fn ffi_clean(header: &str) -> Result<String, String> {
    let mut active: Vec<bool> = Vec::new();
    let mut out = String::new();

    for line in header.lines() {
        let directive = line; // directives start in column 0; a doc comment line such as ` # Safety` does not
        if let Some(rest) = directive.strip_prefix("#if ") {
            if !rest.contains("__STDC_VERSION__") {
                return Err(format!("an unexpected preprocessor condition: {line}"));
            }

            active.push(false); // the C23 branch is dropped
        } else if directive.starts_with("#else") {
            let top = active.last_mut().ok_or("an #else without an #if")?;
            *top = !*top;
        } else if directive.starts_with("#endif") {
            active.pop().ok_or("an #endif without an #if")?;
        } else if directive.starts_with('#') {
            return Err(format!("a preprocessor line FFI cannot read: {line}"));
        } else if active.iter().all(|branch| *branch) {
            out.push_str(line);
            out.push('\n');
        }
    }

    if !active.is_empty() {
        return Err("an #if that is never closed".to_owned());
    }

    Ok(out)
}

#[cfg(test)]
mod tests {
    use super::ffi_clean;

    #[test]
    fn it_keeps_the_pre_c23_branch_and_drops_the_directives() {
        let input = "enum A\n#if __STDC_VERSION__ >= 202311L\n  : int32_t\n#endif // x\n {\n  X = 0,\n};\n#if __STDC_VERSION__ >= 202311L\ntypedef enum A A;\n#else\ntypedef int32_t A;\n#endif // y\n";

        let output = ffi_clean(input).expect("clean");

        assert_eq!(output, "enum A\n {\n  X = 0,\n};\ntypedef int32_t A;\n");
    }

    #[test]
    fn it_refuses_what_ffi_cannot_read() {
        for input in [
            "#include <stdint.h>\n",
            "#define X 1\n",
            "#ifndef G\n#endif\n",
            "#if FOO\n#endif\n",
            "#endif\n",
            "#else\n",
            "#if __STDC_VERSION__ >= 1\n",
        ] {
            assert!(ffi_clean(input).is_err(), "{input:?} should be refused");
        }
    }

    #[test]
    fn a_doc_comment_line_that_starts_with_a_hash_is_not_a_directive() {
        let input = "/*\n # Safety\n The pointer must be valid.\n */\nvoid f(void);\n";

        assert_eq!(ffi_clean(input).expect("clean"), input);
    }

    #[test]
    fn plain_declarations_pass_through_unchanged() {
        let input = "/* a comment */\nuint32_t f(void);\n";

        assert_eq!(ffi_clean(input).expect("clean"), input);
    }
}
