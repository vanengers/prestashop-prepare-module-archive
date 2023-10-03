# prestashop-prepare-module-archive
Prepare module for addons / validator upload.
It will update composer within the production environment. (--no-dev parameter)

## Install
`` composer require --dev vanengers/prestashop-prepare-module-archive``

## Useage
`` php vendor/bin/archive --module=MODULE_NAME``

You can specify an alternative folder for the module. (default: current folder)
`` php vendor/bin/archive --module=MODULE_NAME --path=MODULE_FOLDER``

You can exclude extra folders from the ZIP archive. (default: none)
`` php vendor/bin/archive --module=MODULE_NAME --exclude=folder1,folder2``