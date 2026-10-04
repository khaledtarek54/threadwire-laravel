# Contributing

Thanks for helping. Bug reports and ideas are welcome as [issues](https://github.com/khaledtarek54/threadwire-laravel/issues).

This repository is a read-only copy of the package's folder in Threadwire's main codebase, where it is tested against the real API and webhook signing. Pull requests here are read, and good ones are applied upstream with credit, then appear here with the next release.

Before opening a pull request:

```sh
composer install
composer test     # Pest
composer lint     # Laravel Pint
```

Please keep changes small and focused, add a test for any behaviour you change, and describe the "why" in the pull request.
