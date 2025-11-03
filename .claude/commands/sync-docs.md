---
description: Analyze last commit and update CLAUDE.md with essential information only
---

# Update CLAUDE.md based on last commit

You are tasked with updating CLAUDE.md to reflect the most recent code changes. Your goal is to keep this file **aggressively simple** and **only include essential information** that makes Claude Code more effective.

## Step 1: Analyze the last commit

Run these commands in parallel:
- `git log -1 --pretty=format:"Commit: %h%nAuthor: %an%nDate: %ad%nMessage: %B"` - Get commit details
- `git diff HEAD~1 HEAD --stat` - Get file change summary
- `git diff HEAD~1 HEAD` - Get full diff

## Step 2: Determine relevance

Ask yourself: **Does this commit change anything that Claude Code needs to know?**

Only update CLAUDE.md if the commit involves:
- ✅ New entities or entity relationship changes
- ✅ New services or significant service changes
- ✅ New features or major workflows
- ✅ New routes or controllers
- ✅ New console commands
- ✅ Architecture changes
- ✅ Configuration changes that affect development
- ❌ Bug fixes (unless they reveal wrong documentation)
- ❌ Minor refactoring
- ❌ UI/styling changes
- ❌ Test changes
- ❌ Documentation changes

If nothing relevant changed, respond: "No significant changes to document in CLAUDE.md"

## Step 3: Update CLAUDE.md (if relevant changes found)

Read the current CLAUDE.md and update it following these principles:

### Aggressive Simplification Rules:
1. **Remove outdated information** - If code was deleted or replaced, remove it from docs
2. **Consolidate redundancy** - If multiple sections say similar things, merge them
3. **Remove implementation details** - Don't document every field or method, only what matters
4. **Focus on relationships** - How do components connect? What's the data flow?
5. **Highlight the "why"** - Not what the code does, but why it exists and when to use it
6. **Keep examples minimal** - One clear example is better than exhaustive coverage

### What to include:
- Entity relationships (User has many Items, etc.)
- Key service purposes and when to use them
- Important workflows (how features work end-to-end)
- Development commands needed to work effectively
- Routes and their purposes
- Configuration that affects how you code

### What to exclude:
- Detailed field lists (unless critical to understanding)
- Every method signature
- Obvious information
- Information that can be discovered by reading code
- Historical context or reasons why decisions were made
- Testing strategies

## Step 4: Make the update

After reading CLAUDE.md, use the Edit tool to update only the relevant sections. Keep changes surgical and focused.

If major simplification is needed, be bold about removing entire sections that aren't essential.

**Remember**: CLAUDE.md should be a 1-page quick reference for Claude Code, not comprehensive documentation.