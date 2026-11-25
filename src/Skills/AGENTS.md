# Skills Module

Loads skills in the Agent Skills format (<https://agentskills.io>): a folder holding a `SKILL.md` file, with instructions in its YAML frontmatter and body, and the files it refers to.

## Usage

```php
use NeuronAI\Agent\Agent;
use NeuronAI\Skills\Skills;

class MyAgent extends Agent
{
    protected function tools(): array
    {
        return [
            // A path uses the filesystem, or pass any StorageInterface
            Skills::make(__DIR__.'/skills'),
            Skills::make(DatabaseStorage::make($id), 'more/skills'),
        ];
    }
}
```

Registering `Skills` is the opt-in: an agent without it gets no skill tool and scans no directory.

## How It Works

1. **Discovery.** `Skills` scans the immediate children of a directory and takes every folder holding a `SKILL.md`. Discovery is not recursive: call `Skills::make()` once per folder.
2. **One tool.** A source with skills provides a single `skill` tool. `skill(name)` returns the instructions of the skill, without the frontmatter. `skill(name, file)` returns a file bundled in the skill folder, for example `reference/guide.md`.
3. **Shared register.** When the agent bootstraps its tools, every skill tool adds its skills to one `SkillRegister`, kept in the state under `__skills`. The first tool survives the de-duplication and serves the skills of all the sources. A skill whose name is already registered is ignored: `SkillRegister::skipped()` lists them.
4. **Unbound tools.** A skill tool that never receives a state (a custom workflow without `Agent::bootstrapTools()`) serves the skills of its own source.

Nothing is loaded into the context until the model calls the tool. Every skill name and description travels in the tool description on each request.

## What Makes a Skill

| `SKILL.md` | Outcome |
|------------|---------|
| A YAML frontmatter mapping with a `name` and a `description` | Loaded |
| No frontmatter, invalid YAML, missing name or missing description | Skipped and reported in `errors()` |
| Name different from the folder, uppercase or spaced, longer than 64 characters; description longer than 1024 characters | Loaded and reported in `errors()` |

The format wants lowercase names matching the folder. The rules are not enforced: real world plugins break them (the OpenAI reference plugins included) and a skill with a name and a description is usable anyway. Skills are registered under the name of their frontmatter.

## Write a Skill

A skill is a folder with a `SKILL.md`. Its frontmatter needs a `name` and a `description`. The description is what the model reads to decide whether to load the skill.

```markdown
---
name: acme-search
description: Search the Acme documentation for a topic. Use when the user asks how Acme works.
---

# Acme search

1. Call `search` with the topic the user asks about.
2. Read the page of the best result before answering.

The query syntax is described in reference/query-syntax.md.
```

- Give the skill the name of its folder, in lowercase with hyphens: the format requires it.
- Say in the description when to use the skill, in one or two sentences. Every description travels in the tool description on each request.
- Bundled files (reference documents, scripts) stay next to `SKILL.md`. Refer to them by path relative to the skill folder: the model reads them with `skill(name, file)`. Neuron never executes them.

## Reading Files

The tool refuses paths that are not relative to the skill folder, and files that are not valid UTF-8 text, that contain null bytes, or that exceed 1 MB (`SkillTool::MAX_FILE_BYTES`). Expected problems are returned as `Error: ...` strings, not thrown.

## Key Files

| File | Purpose |
|------|---------|
| `Skills.php` | Toolkit: discovery, validation, `skills()`, `errors()` |
| `Skill.php` | A skill: name, description, storage and folder. `read()`, `body()` |
| `SkillTool.php` | The `skill` tool |
| `SkillRegister.php` | The skills available to an agent, by name |
| `Frontmatter.php` | Splits a document into YAML frontmatter and markdown body |

## Dependencies

`Tools` (toolkits), `Storage` (files), `symfony/yaml` (frontmatter).
