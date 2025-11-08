# DISCORD.md Documentation and CLAUDE.md Updates

**Status**: Not Started
**Priority**: Medium
**Estimated Effort**: Small
**Created**: 2025-11-08

## Overview

Create comprehensive DISCORD.md documentation covering Discord integration setup, configuration, features, and usage. Update CLAUDE.md to reference DISCORD.md and establish a process for keeping documentation in sync with future Discord features.

## Problem Statement

The Discord integration adds significant functionality to the CS2 Inventory system. Administrators and developers need clear documentation for:
- Setting up Discord bot and webhooks
- Configuring the integration
- Managing user account links
- Understanding available commands and notifications
- Troubleshooting common issues

Additionally, CLAUDE.md should be updated to remind Claude to maintain DISCORD.md when working on Discord-related features.

## Requirements

### Functional Requirements

#### DISCORD.md Must Include:
1. **Overview** - What the Discord integration does
2. **Prerequisites** - Discord server admin access, bot permissions
3. **Discord Developer Portal Setup** - Creating bot application, getting tokens
4. **Bot Permissions & Intents** - Required settings in Developer Portal
5. **Webhook Setup** - Creating webhooks for notifications
6. **Application Configuration** - Environment variables, database setup
7. **User Account Linking** - How users link Discord accounts
8. **Available Commands** - List all bot commands with usage examples
9. **Notification Types** - What events trigger notifications
10. **Admin UI Guide** - Using the Discord settings page
11. **Troubleshooting** - Common issues and solutions
12. **Architecture Overview** - How the system works (for developers)

#### CLAUDE.md Must Include:
- Reference to DISCORD.md
- Instruction to update DISCORD.md when adding/changing Discord features
- Brief summary of Discord integration components

### Non-Functional Requirements
- Clear, step-by-step instructions for non-technical admins
- Screenshots or code examples where helpful
- Well-organized with table of contents
- Markdown formatting for GitHub/GitLab rendering
- Maintain consistency with existing documentation style

## Technical Approach

### File Structure

```
/home/gmurcia/projects/personal/cs2inventory/
├── DISCORD.md (new)
└── CLAUDE.md (update)
```

### DISCORD.md Structure

```markdown
# Discord Integration

## Table of Contents
1. Overview
2. Features
3. Setup Guide
   - Prerequisites
   - Discord Developer Portal Setup
   - Bot Permissions & Intents
   - Webhook Setup
   - Application Configuration
4. User Account Linking
5. Bot Commands
6. Notification Types
7. Admin Interface
8. Troubleshooting
9. Architecture (Developer Reference)

## Overview
[Description of what Discord integration provides]

## Features
- Outbound notifications via webhooks
- Interactive bot commands
- User account linking
- Admin management UI

## Setup Guide

### Prerequisites
[List requirements]

### Discord Developer Portal Setup
[Step-by-step with screenshots/links]

### Bot Permissions & Intents
[Checklist of required settings]

### Webhook Setup
[How to create and configure webhooks]

### Application Configuration
[Environment variables, database setup]

## User Account Linking
[How users link their Discord accounts]

## Bot Commands

### !help
[Usage, description, example]

### !price [item_name]
[Usage, description, examples, screenshots]

[Future commands...]

## Notification Types

### System Events
[What triggers them, what they contain, example screenshot]

[Future notification types...]

## Admin Interface
[Guide to using /admin/discord page]

## Troubleshooting
[Common issues and solutions]

## Architecture (Developer Reference)
[System components, data flow, entities, services]
```

### CLAUDE.md Updates

Add new section or update existing sections:

```markdown
## Discord Integration

The system integrates with Discord for notifications and interactive commands.

**Documentation**: See `DISCORD.md` for complete setup and usage guide.

**Key Components**:
- **Webhooks**: Outbound notifications (system events, etc.)
- **Bot Commands**: Interactive commands (!price, !inventory, etc.)
- **User Linking**: Discord accounts linked to User entities via DiscordUser table

**IMPORTANT**: When implementing new Discord features:
1. Update DISCORD.md with new commands, notifications, or configuration
2. Update the "Bot Commands" or "Notification Types" section as appropriate
3. Add any new environment variables to the setup guide
4. Update troubleshooting section if new issues may arise
```

## Implementation Steps

1. **Research Existing Documentation Style**
   - Read CLAUDE.md to understand tone and formatting
   - Read PRODUCTION.md (if exists) for style consistency
   - Note use of code blocks, headings, examples

2. **Create DISCORD.md File**
   - Create `/home/gmurcia/projects/personal/cs2inventory/DISCORD.md`
   - Add table of contents with anchor links
   - Use consistent heading levels

3. **Write Overview Section**
   - Brief description of Discord integration purpose
   - List key features (notifications, commands, linking)
   - Link to Discord documentation for reference

4. **Write Features Section**
   - List all current features:
     - Outbound webhook notifications (system events)
     - Discord bot with command system
     - User account linking with verification
     - Admin settings UI
   - Use bullet points or feature cards

5. **Write Setup Guide - Prerequisites**
   - Discord server admin access
   - Discord account with Developer Portal access
   - CS2 Inventory application installed and running
   - Access to application .env file and database

6. **Write Setup Guide - Discord Developer Portal**
   - Step 1: Go to https://discord.com/developers/applications
   - Step 2: Create New Application (name: "CS2 Inventory Bot")
   - Step 3: Go to "Bot" tab → Add Bot
   - Step 4: Copy bot token (save securely)
   - Step 5: Enable Privileged Gateway Intents:
     - ✅ MESSAGE CONTENT INTENT (required)
     - ✅ SERVER MEMBERS INTENT (optional)
   - Step 6: Save changes
   - Include screenshots or links to Discord docs

7. **Write Setup Guide - Bot Permissions**
   - List required permissions:
     - View Channels
     - Send Messages
     - Embed Links
     - Read Message History
   - Explain how to generate invite URL:
     - OAuth2 → URL Generator
     - Select scopes: `bot`
     - Select permissions (above list)
     - Copy URL and invite to server

8. **Write Setup Guide - Webhook Setup**
   - How to create webhook in Discord:
     - Server Settings → Integrations → Webhooks → New Webhook
     - Choose channel for notifications
     - Copy webhook URL
   - Where to paste URL (admin UI or .env)

9. **Write Setup Guide - Application Configuration**
   - List environment variables:
     ```
     DISCORD_BOT_TOKEN=your_bot_token_here
     DISCORD_WEBHOOK_SYSTEM_EVENTS=https://discord.com/api/webhooks/...
     ```
   - Database setup (migrations should auto-run)
   - Starting the bot:
     ```bash
     docker compose up -d discord-bot
     # OR
     docker compose exec php php bin/console app:discord:bot:start
     ```

10. **Write User Account Linking Section**
    - Manual linking process:
      1. User gets their Discord ID (right-click username → Copy ID)
      2. User submits link request (future: web form, current: manual)
      3. Admin verifies in admin UI (/admin/discord)
    - Verification requirement for bot commands
    - Security considerations

11. **Write Bot Commands Section**
    - Format for each command:
      ```markdown
      ### !help
      **Description**: List available commands or get help for specific command
      **Usage**: `!help [command]`
      **Examples**:
      - `!help` - List all commands
      - `!help price` - Get help for !price command

      **Permissions**: All linked users
      ```
    - Document !help command
    - Document !price command (from Task 41)
    - Leave placeholders for future commands

12. **Write Notification Types Section**
    - Document System Events notifications:
      - Triggered when: Steam item sync completes, errors occur
      - Contains: Event title, description, timestamp, color-coded
      - Example screenshot or embed structure
    - Leave placeholders for future notification types

13. **Write Admin Interface Section**
    - How to access: `/admin/discord` (requires ROLE_ADMIN)
    - Sections:
      - Webhook Configuration (how to set URLs, enable/disable)
      - Linked Users Management (verify, unverify, delete)
      - Recent Notifications (view history)
      - Test Webhook (how to use)
    - Screenshots or step-by-step guide

14. **Write Troubleshooting Section**
    - Common issues:
      - Bot not responding → Check MESSAGE_CONTENT intent enabled
      - Webhook 404 error → Webhook was deleted, create new one
      - "Please link your Discord account" → User not in DiscordUser table
      - "Pending verification" → Admin must verify in UI
      - Commands not working → Check bot is running (`docker ps`)
      - No notifications → Check webhook URL configured and enabled
    - How to check logs
    - How to restart bot

15. **Write Architecture Section (Developer Reference)**
    - System components:
      - Entities: DiscordConfig, DiscordUser, DiscordNotification
      - Services: DiscordWebhookService, DiscordBotService, DiscordCommandRegistry
      - Commands: DiscordBotCommand, TestWebhookCommand
      - Controllers: DiscordAdminController
    - Data flow diagram (text or ASCII art)
    - How to add new bot commands (implement DiscordCommandInterface)
    - How to add new notification types (add webhook config, call service)

16. **Update CLAUDE.md**
    - Find appropriate section (after "Core Architecture" or "Development Commands")
    - Add "Discord Integration" section
    - Include:
      - Brief summary of Discord features
      - Link to DISCORD.md for details
      - Instruction for Claude to update DISCORD.md when adding features
      - List of key components (entities, services)
      - Reminder about updating documentation

17. **Add DISCORD.md to Git**
    - Add file to git: `git add DISCORD.md`
    - Commit with other Discord changes (or separate commit)

## Edge Cases & Error Handling

- **Missing screenshots**: Describe steps clearly enough that screenshots are optional
- **Discord UI changes**: Link to official Discord docs for steps that may change
- **Version-specific instructions**: Note DiscordPHP version (10.x) in case of breaking changes
- **Outdated documentation**: Add "Last updated" date at top of DISCORD.md
- **Long document**: Use table of contents with anchor links for easy navigation
- **Code examples**: Use syntax highlighting (```bash, ```php) for readability

## Dependencies

### Blocking Dependencies
- Task 38: Discord database foundation (document entities)
- Task 39: Discord webhook service (document webhooks, notifications)
- Task 40: Discord bot foundation (document bot setup, commands)
- Task 41: Discord bot !price command (document command usage)
- Task 42: Discord admin settings UI (document admin interface)

### Related Tasks (Discord Integration Feature)
- All other Discord tasks (this is the final task)

### Can Be Done in Parallel With
- None (should be done after all features are implemented)

### External Dependencies
- None

## Acceptance Criteria

- [ ] DISCORD.md created with all required sections
- [ ] Table of contents with working anchor links
- [ ] Overview and features sections completed
- [ ] Setup guide covers Discord Developer Portal, bot creation, permissions, intents
- [ ] Webhook setup instructions included
- [ ] Application configuration documented (env vars, Docker)
- [ ] User account linking process explained
- [ ] All current bot commands documented (!help, !price)
- [ ] All current notification types documented (system events)
- [ ] Admin UI usage guide included
- [ ] Troubleshooting section covers common issues
- [ ] Architecture section provides developer reference
- [ ] CLAUDE.md updated with Discord section
- [ ] CLAUDE.md instructs to update DISCORD.md when adding features
- [ ] Code examples use proper syntax highlighting
- [ ] Markdown formatting is consistent and renders correctly
- [ ] No broken links
- [ ] File committed to git

## Manual Verification Steps

1. **Read Through Documentation**
   - Open DISCORD.md in Markdown viewer or GitHub
   - Verify table of contents links work
   - Check all sections are complete and clear

2. **Follow Setup Guide**
   - Create new Discord bot following the guide
   - Verify all steps are accurate and complete
   - Note any missing steps or unclear instructions

3. **Test Commands Documentation**
   - Try each documented command in Discord
   - Verify usage examples are correct
   - Check that output matches documentation

4. **Review with Fresh Eyes**
   - Ask someone unfamiliar with the system to read the guide
   - Note any confusing sections
   - Revise as needed

5. **Check CLAUDE.md Updates**
   - Open CLAUDE.md
   - Verify Discord section is present and accurate
   - Check that instructions for Claude are clear

6. **Verify Git Commit**
   ```bash
   git status
   # Should show DISCORD.md and CLAUDE.md as modified/added

   git diff DISCORD.md
   # Review changes

   git diff CLAUDE.md
   # Review changes
   ```

## Notes & Considerations

- **Living Document**: DISCORD.md should be updated whenever Discord features are added/changed
- **Screenshots**: Consider adding screenshots for Discord Developer Portal steps (optional but helpful)
- **Video Tutorial**: Future enhancement - create video walkthrough of setup process
- **Multi-language**: Currently English only, consider translations for non-English users
- **Versioning**: Add version number or last updated date to track changes
- **Examples**: Include real examples where possible (sanitize sensitive data like tokens)
- **Links**: Link to official Discord documentation for steps that may change over time
- **Feedback**: Include section for users to report documentation issues (GitHub issues, email)

## Related Tasks

- Task 38: Discord database foundation (entities to document)
- Task 39: Discord webhook service (webhook setup, notifications)
- Task 40: Discord bot foundation (bot setup, architecture)
- Task 41: Discord bot !price command (command documentation)
- Task 42: Discord admin settings UI (admin interface guide)

**This task depends on all other Discord tasks being completed first.**
