# Rename maps

One map per step of the English identifiers work (docs/glossary.md), applied with

    php tools/rename.php tools/rename/<step>.php            # dry run
    php tools/rename.php tools/rename/<step>.php --apply

After applying: run the tests (vendor/bin/phpunit, tools/test-english.sh), commit the
rename alone, and add the commit hash to .git-blame-ignore-revs in the next commit.
