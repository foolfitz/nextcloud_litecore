// The Files action: an ODT opens in the editor route. It navigates instead of
// opening in place because the editor needs its own cross-origin-isolated
// document, and isolating Files itself is the shape of
// nextcloud/richdocuments#4103 (study_LiteCore M3 gate 0, RESULT.md).
import { DefaultType, Permission, registerFileAction } from '@nextcloud/files'
import { generateUrl } from '@nextcloud/router'

const ODT = 'application/vnd.oasis.opendocument.text'
const ICON = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="currentColor" d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8zm-1 7V3.5L18.5 9zM7 13h10v2H7zm0 4h7v2H7z"/></svg>'

registerFileAction({
	id: 'litecore-open',
	displayName: () => 'Open in LiteCore',
	iconSvgInline: () => ICON,
	enabled: ({ nodes }) => nodes.length === 1 && nodes[0].mime === ODT
		&& (nodes[0].permissions & Permission.READ) !== 0,
	async exec({ nodes }) {
		window.location.assign(generateUrl('/apps/litecore/r/e2-editor.html?fileId={fileId}',
			{ fileId: nodes[0].fileid }))
		return null
	},
	default: DefaultType.DEFAULT,
	order: -1,
})
