# Admidio Language Overrides Plugin — change how Admidio words things

A third-party plugin for Admidio 6.0 and later. Administrators search all texts of Admidio and replace
any of them with their own wording, for example "Mitgliederliste" instead of "Kontakte", without
editing XML files.

## Installation

As administrator, go to Admidio's plugin manager, select "Add plugin" and upload the plugin ZIP file,
then enable the plugin. The ZIP is built from a checkout of this repository in
`plugins/language-overrides` of an Admidio installation:

```
php admidio plugin:archive language-overrides
```

The plugin has no database tables. Its page appears in the menu below "Extensions" for administrators
only. Its texts are available in English and German (du and Sie).

## How it works

Admidio reads `adm_my_files/languages/<language>.xml` before its own language file of that language
(and before the language files of the plugins), so an entry in such a file replaces the text. The
plugin is the interface to these files:

* **Find a text:** live search (as you type) by what a text says or by its text id, in one language chosen in a dropdown
  and, if ticked (the default), in English too. With "Regular expression" ticked the query is a PHP
  (PCRE) expression, case-insensitive and without delimiters, matched against the texts as written in the
  files (`^Kont.*e$`) and against the text ids; an invalid expression is reported. Core and plugin texts are searched. Texts that only
  the installer shows (`INS_…`) are left out unless the setting says otherwise.
* **Change a text:** shows the original and your text for the language, and for English if ticked. A
  text equal to the original removes the override; a language you did not edit is not written. For
  German, a checkbox (ticked by default) gives the other form of address, `de` (du) or `de-DE` (Sie),
  the same text.
* **Your overrides:** all entries of all override files with their original and these states: the
  original text was changed by an update since the override was written, the text id no longer exists,
  or the override leaves out a value (`#VAR1#`) that the original shows. "Restore the original"
  removes an override.

Things to know:

* An override belongs to **one language**. A text changed in `en` is not seen by users of `de`, because
  the German file has the text already. `de` and `de-DE` are separate languages.
* Overrides are valid for the whole installation, not for one organization.
* Only text ids that Admidio looks up can be changed. Names that users typed into the database (roles,
  categories, lists) and the country names are not language texts.
* Entries carry an attribute `original` with the text of the core at the time. Admidio ignores it.
* A text may use the values `#VAR1#`, `#VAR2#` … the original uses. A value that is not in the original
  is refused. A line break is typed as a new line and stored as `\n`.
* Saving rebuilds the running sessions (`Session::reloadAllSessions()`), which is what makes a new or
  changed file effective for users who are logged in.

## Files and backups

A file is changed under a lock, by editing the document that is there, so comments in it survive, and is
replaced with a rename. Before that the old file is copied to the backup folder of the installation (the
preference `system_backup_path`, by default `adm_my_files/backups/`) into the subfolder
`language-overrides/`, as `<language>-YYYYMMDD-HHMMSS-mmm.xml`. The setting "Backups per language"
(default 20) says how many are kept; if the copy cannot be made, nothing is changed.

A form that was opened before somebody else changed the file is refused ("open the text again").

## Not in this version

* Find-and-replace of a word over many texts, with a preview.
* Export and import of the override files, a command line interface.
* A check of the markup in a text. A text goes into pages as HTML like every text of Admidio does, and
  only administrators can write it.

## Tests

```
vendor/bin/phpunit plugins/language-overrides/tests
```
