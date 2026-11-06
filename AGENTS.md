# AGENTS.md

## What this is

Scratch workspace for testing OpenCode. **No application code, no `package.json`, no build/test/lint tooling, not a git repo.** There is nothing to build, test, or lint here, so skip all verification rituals (lint -> typecheck -> test does not apply). The directory name suggests an SPP app for SMK Bina Bangsa, but no app code has been written yet.

Environment: Windows, PowerShell. Node v24 and npx are installed; the `opencode` binary is not on PATH — OpenCode runs as the host harness, not as a local command.

## `opencode.json`

- **One local MCP server**, `document-generator`, launched via `npx -y document-generator-mcp@latest`.
- `timeout` is `30000` on purpose: the first launch downloads the package from npm and blows past the 5s default. Don't "fix" it back down.
- Tools: `gerar_documento_word` (formats `word|pdf|ambos`, templates `professional|minimalist|corporate|default`) and `gerar_documento_pdf`. `nome_arquivo`, `titulo_documento`, `conteudo_principal` are required. Content is Markdown; JSON blocks are auto-detected and syntax-highlighted.
- Generated files land in `./generated_documents/` under the workspace. Safe to delete.
- **No `provider` or `model` block here** — models come from global config / env. If models fail to load, that's the place to look, not this file.

## `.agents/skills/` and `skills-lock.json`

- `skills-lock.json` pins the vendored `frontend-design` skill (source `anthropics/skills`, hash-checked). Don't hand-edit it; add/remove skills through the OpenCode skill workflow instead.
- `.agents/skills/frontend-design/` holds `SKILL.md` + `LICENSE.txt`. Load it via the skill tool for UI/visual design work. The directory tree belongs to the skill, not to project code — do not refactor or "clean it up".
