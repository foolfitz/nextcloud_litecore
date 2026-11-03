# Icons

`oasis-text.svg` and `oasis-spreadsheet.svg` are LibreOffice's own document icons, the ones a desktop shows for an .odt and an .ods file:
`sysui/desktop/icons/hicolor/scalable/mimetypes/oasis-text.svg` and `oasis-spreadsheet.svg` in the LibreOffice source (core, as of the
libreoffice-26-8 branch; redrawn for tdf#132398 in 2022). The XML declaration, the editor comment and the `version` and `xml:space`
attributes were removed; the drawing is unchanged.

They are licensed under the Mozilla Public License, v. 2.0, whose text is in `../LICENSES/MPL-2.0.txt`
(also at https://mozilla.org/MPL/2.0/). This app as a whole is AGPL; these two files stay under the MPL.

They are the icons of "New document" and "New spreadsheet" in Files' New menu (`lib/Listener/RegisterTemplateCreatorListener.php`).
