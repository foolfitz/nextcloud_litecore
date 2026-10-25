// The Files action: an ODT opens in the editor route, an ODS in the read-only
// viewer route (study_LiteCore SPEC-M4-ods-in-nextcloud §3.4). ONE action whose
// `enabled` takes both mimes and whose `exec` picks the route by mime: a second
// action with the same id would be dropped by @nextcloud/files without a word
// (the review's M3). It navigates instead of opening in place because both pages
// need their own cross-origin-isolated document, and isolating Files itself is
// the shape of nextcloud/richdocuments#4103 (study_LiteCore M3 gate 0, RESULT.md).
import { DefaultType, Permission, registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'

const ODT = 'application/vnd.oasis.opendocument.text'
const ODS = 'application/vnd.oasis.opendocument.spreadsheet'
// mime -> the page the controller's page table serves for it.
const PAGES = { [ODT]: 'e2-editor.html', [ODS]: 'ods-viewer.html' }
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm-1 7V3.5L18.5 9zM7 13h10v2H7zm0 4h7v2H7z"/></svg>'

registerFileAction({
	id: 'litecore-open',
	displayName: () => 'Open in LiteCore',
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => nodes.length === 1 && Object.hasOwn(PAGES, nodes[0].mime)
		&& (nodes[0].permissions & Permission.READ) !== 0,
	async exec({ nodes }) {
		const node = nodes[0]
		const page = node.mime === ODS && (node.permissions & Permission.UPDATE) !== 0
			? 'ods-editor.html' : PAGES[node.mime]
		window.location.assign(generateUrl('/apps/litecore/r/{page}?fileId={fileId}',
			{ page, fileId: node.fileid }))
		return null
	},
	default: DefaultType.DEFAULT,
	order: -1,
})
