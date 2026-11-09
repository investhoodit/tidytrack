# tidytrack

## Configuration

Set these environment variables in the PHP runtime before starting the application:

- `TIDYTRACK_DB_HOST`
- `TIDYTRACK_DB_USER`
- `TIDYTRACK_DB_PASSWORD`
- `TIDYTRACK_DB_NAME`
- `TIDYTRACK_SMTP_USERNAME`
- `TIDYTRACK_SMTP_PASSWORD`
- `TIDYTRACK_REMINDER_SMTP_PASSWORD` (used by the member task reminder mailer)
- `TIDYTRACK_GOOGLE_CLIENT_ID`
- `TIDYTRACK_GOOGLE_CLIENT_SECRET`
- `TIDYTRACK_GOOGLE_REDIRECT_URI`
- `TIDYTRACK_GEMINI_API_KEY`
- `TIDYTRACK_OPENAI_API_KEY`
- `TIDYTRACK_LIVEKIT_API_KEY`
- `TIDYTRACK_LIVEKIT_API_SECRET`

The database, Google sign-in, and LiveKit settings are required for their respective features. SMTP and AI service settings are needed only when those features are used.
