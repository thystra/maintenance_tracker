import { createAppConfig } from '@nextcloud/vite-config'
import { join, resolve } from 'node:path'

export default createAppConfig(
	{
		main: resolve(join('src', 'main.ts')),
	},
	{
		config: {
			build: {
				sourcemap: false,
			},
		},
		createEmptyCSSEntryPoints: true,
		extractLicenseInformation: true,
		thirdPartyLicense: false,
	},
)
