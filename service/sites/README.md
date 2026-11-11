# Site folders

One folder per client site, created with:

```bash
python service/peak_brain.py init service/sites/<name> --site-url https://example.com
```

Each holds `config.json` and `SITE.md` (the notes file). These folders are git-ignored because they hold client details.
