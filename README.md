# Archive Manager — Your files, on your server

A lightweight and secure, password-protected PHP tool to **create, upload, inspect, extract and download ZIP archives** directly from your browser. No shell access or database required.

**Available versions:** `archive-manager-en.php` (English) and `archive-manager-fr.php` (French). Both provide the same features; use only the language you need.

## Requirements

- PHP **8.2+ (64-bit)** with the **ZipArchive** extension, PHP sessions and file-writing permissions.
- HTTPS access to the manager. Your hosting limits for uploads, execution time and disk space still apply.
- An existing folder that you are authorized to manage.

## Install and sign in

1. Upload **one** version (`archive-manager-en.php` or `archive-manager-fr.php`) to the folder you want to manage.
2. Open the PHP file in your browser over **HTTPS**. On first launch, enter a strong password and generate its hash.
3. Copy the generated `const AM_PASSWORD_HASH = '...';` line into the configuration section of that PHP file, replacing `const AM_PASSWORD_HASH = '';`.
4. Save and upload the edited file, refresh the page, then sign in with your password.

You can also generate a password hash via the script's command-line setup, if PHP CLI is available. **Never put the plain-text password in the PHP file.** When switching language versions, copy your existing password hash and any custom configuration to the other file.

## Create a ZIP

1. Under **Create ZIP**, enter a file or folder in **Source**: `.` for the entire managed folder, or a relative path such as `wp-content/uploads`.
2. Choose the **ZIP filename** and compression level.
3. Choose **Private storage** to download it from the protected interface, or **Server folder** to save the ZIP directly to an existing directory.
4. For **Server folder**, set the destination to `.` (managed root) or a relative folder such as `backups`. Confirm the public-download warning if shown, then click **Create archive**.

**Examples:** source `.` + server destination `.` creates `backup.zip` in the managed root; source `wp-content` + server destination `backups` creates it inside `backups/` (the folder must already exist). The filename must not contain a directory path.

Both modes create the archive **on the server**. Private storage lets you download it to your computer afterwards. Existing ZIPs are not overwritten. Some manager, version-control and symbolic-link files are excluded.

## Upload, inspect and extract

1. Use **Upload ZIP** to send an archive from your computer. Uploads go to private storage; **uploading does not extract** the files.
2. Use **Inspect ZIP / View contents** to preview its entries. You may also select a ZIP already in the managed folder.
3. Under **Extract ZIP**, choose the source ZIP and enter a **relative destination**. Use `.` to extract directly into the managed root.
4. Check **Use an existing folder** when extracting into `.` or another folder already present.
5. Check **Allow website and configuration files** only for trusted archives containing PHP, `.htaccess` or similar website files. Click **Check and extract**.

The folder structure **inside the ZIP is preserved**: an archive containing `wordpress/` will extract into a `wordpress/` folder. Files already present in the destination are **never overwritten**; conflicts block extraction. Absolute paths, `../`, unsafe ZIP entries and symbolic links are rejected.

## Private archives and file browser

- The **Files** section lists files under the allowed root and helps select a source or ZIP.
- **Private archives** lists created/imported ZIPs and lets authenticated users **inspect, download or delete** them.
- Default private storage is under the server's temporary directory and **may be cleaned automatically**. Download backups you intend to keep, or configure a persistent private location.

## Main settings

Edit the constants near the top of the PHP file:

| Setting | Purpose |
|---|---|
| `AM_PASSWORD_HASH` | Password hash for sign-in; required. |
| `AM_ROOT` | Managed root folder (`__DIR__` by default). |
| `AM_PRIVATE_DIR` | Optional private storage directory **outside the public web root**. |
| `AM_REQUIRE_HTTPS` | Require HTTPS (enabled by default). |
| `AM_ALLOWED_IPS` | Optional IP allowlist. |
| `AM_MAX_SECONDS` | Application execution limit (default: 120 seconds, subject to server limits). |
| `AM_MAX_FILE_BYTES` | Maximum size per file (default: 512 MiB). |
| `AM_MAX_TOTAL_BYTES` | Maximum total uncompressed/source size (default: 2 GiB). |
| `AM_MAX_ARCHIVE_BYTES` | Maximum ZIP size (default: 2 GiB). |
| `AM_MAX_ENTRIES` | Maximum entries per archive (default: 20,000). |
| `AM_MAX_SAVED` / `AM_MAX_STORED_BYTES` | Private archive count/size limits (20 / 4 GiB by default). |

Other settings control session duration, trusted proxies, path depth, compression ratio, disk margin and file exclusions. Do not relax security checks to solve a hosting error.

## Security and troubleshooting

- Access requires a password; sessions and requests are protected. Keep HTTPS enabled and remove unused copies of the manager.
- **A ZIP stored in a public web folder might be downloadable by anyone who knows its URL**, including sensitive configuration files. Prefer private storage for backups.
- Archive checks and extension filtering **are not antivirus scanning**. Only extract trusted archives; ZIP backups do **not** include your database unless you export it separately.
- **HTTP 500:** inspect the PHP error log. **Upload fails:** check `upload_max_filesize` and `post_max_size`. **Extraction fails:** check permissions, free space, size/time limits and destination conflicts. **ZIP not visible:** check that it is inside the managed root or private archive list.
- If the server does not provide a particular `ZipArchive` capability, use an updated compatible version of the manager; the PHP version alone does not guarantee every ZIP method.

**Author:** Mohieddine ABASSI · [my website](https://portfolio.mohiedineabassi.workers.dev/) · **Copyright © 2026** · **License notice:** MIT.

---

# Archive Manager — Vos fichiers, sur votre serveur

Un outil PHP léger, sécurisé et protégé par mot de passe pour **créer, importer, inspecter, extraire et télécharger des archives ZIP** depuis le navigateur. Aucun accès terminal ni base de données nécessaire.

**Deux versions disponibles :** `archive-manager-en.php` (anglais) et `archive-manager-fr.php` (français). Elles proposent les mêmes fonctions ; installez uniquement la langue souhaitée.

## Prérequis

- PHP **8.2+ (64 bits)** avec l'extension **ZipArchive**, les sessions PHP et les droits d'écriture.
- Accès au gestionnaire en HTTPS. Les limites de l'hébergement (import, temps d'exécution, espace disque) restent applicables.
- Un dossier existant que vous êtes autorisé à administrer.

## Installation et connexion

1. Envoyez **une seule** version (`archive-manager-en.php` ou `archive-manager-fr.php`) dans le dossier à gérer.
2. Ouvrez le fichier PHP dans votre navigateur en **HTTPS**. Au premier lancement, choisissez un mot de passe fort et générez son empreinte.
3. Copiez la ligne générée `const AM_PASSWORD_HASH = '...';` dans la configuration du fichier PHP, à la place de `const AM_PASSWORD_HASH = '';`.
4. Enregistrez et renvoyez le fichier modifié, rechargez la page, puis connectez-vous.

Vous pouvez aussi générer l'empreinte en ligne de commande si PHP CLI est disponible. **N'inscrivez jamais le mot de passe en clair dans le PHP.** Pour changer de langue, recopiez votre empreinte et vos réglages personnels dans l'autre version.

## Créer un ZIP

1. Dans **Créer un ZIP**, indiquez la **source** : `.` pour tout le dossier autorisé, ou un chemin relatif comme `wp-content/uploads`.
2. Choisissez le **nom du ZIP** et le niveau de compression.
3. Choisissez **Espace privé** pour le télécharger depuis l'interface protégée, ou **Dossier du serveur** pour l'enregistrer directement dans un dossier existant.
4. En mode **Dossier du serveur**, indiquez `.` (racine autorisée) ou un dossier relatif comme `sauvegardes`. Validez l'avertissement sur l'accès public si affiché, puis cliquez sur **Créer l'archive**.

**Exemples :** source `.` + destination `.` crée `sauvegarde.zip` dans la racine autorisée ; source `wp-content` + destination `sauvegardes` l'enregistre dans `sauvegardes/` (ce dossier doit exister). Le nom du ZIP ne doit pas contenir de chemin.

Dans les deux modes, le ZIP est **d'abord créé sur le serveur**. L'espace privé permet ensuite de le télécharger sur votre ordinateur. Les archives existantes ne sont pas écrasées. Certains fichiers du gestionnaire, dossiers de versionnement et liens symboliques sont exclus.

## Importer, inspecter et extraire

1. Dans **Importer un ZIP**, sélectionnez une archive sur votre ordinateur. Elle est conservée en espace privé ; **l'import ne lance pas l'extraction**.
2. Utilisez **Inspecter le ZIP / Voir le contenu** pour vérifier ses fichiers. Vous pouvez aussi sélectionner un ZIP déjà présent dans le dossier autorisé.
3. Dans **Extraire un ZIP**, choisissez l'archive puis une **destination relative**. Indiquez `.` pour extraire dans la racine autorisée.
4. Cochez **Utiliser un dossier déjà existant** si la destination est `.` ou un dossier déjà créé.
5. Cochez **Autoriser les fichiers de site et de configuration** uniquement pour une archive de confiance contenant du PHP, `.htaccess` ou d'autres fichiers de site. Cliquez sur **Vérifier et extraire**.

L'arborescence **interne du ZIP est conservée** : une archive contenant `wordpress/` créera un dossier `wordpress/`. Les fichiers existants ne sont **jamais écrasés** ; tout conflit bloque l'extraction. Les chemins absolus, `../`, les entrées dangereuses et les liens symboliques sont refusés.

## Archives privées et explorateur

- La section **Fichiers** affiche le contenu de la racine autorisée et aide à sélectionner une source ou un ZIP.
- La section **Archives privées** permet aux utilisateurs connectés d'**inspecter, télécharger ou supprimer** les ZIP créés ou importés.
- Par défaut, l'espace privé utilise un répertoire temporaire du serveur, qui **peut être nettoyé automatiquement**. Téléchargez les sauvegardes à conserver ou configurez un dossier privé permanent.

## Principaux réglages

Modifiez les constantes au début du fichier PHP :

| Réglage | Fonction |
|---|---|
| `AM_PASSWORD_HASH` | Empreinte du mot de passe ; obligatoire. |
| `AM_ROOT` | Racine autorisée (`__DIR__` par défaut). |
| `AM_PRIVATE_DIR` | Dossier privé facultatif **hors du répertoire web public**. |
| `AM_REQUIRE_HTTPS` | Obligation du HTTPS (activée par défaut). |
| `AM_ALLOWED_IPS` | Liste facultative des IP autorisées. |
| `AM_MAX_SECONDS` | Durée maximale applicative (120 secondes par défaut, selon les limites du serveur). |
| `AM_MAX_FILE_BYTES` | Taille maximale par fichier (512 Mio par défaut). |
| `AM_MAX_TOTAL_BYTES` | Volume total source/décompressé (2 Gio par défaut). |
| `AM_MAX_ARCHIVE_BYTES` | Taille maximale du ZIP (2 Gio par défaut). |
| `AM_MAX_ENTRIES` | Nombre maximal d'entrées (20 000 par défaut). |
| `AM_MAX_SAVED` / `AM_MAX_STORED_BYTES` | Nombre/volume des archives privées (20 / 4 Gio par défaut). |

D'autres options règlent les sessions, les proxys de confiance, la profondeur des chemins, le taux de compression, la marge disque et les exclusions. Ne désactivez pas les protections pour contourner une erreur d'hébergement.

## Sécurité et dépannage

- L'accès est protégé par mot de passe, sessions et contrôles des requêtes. Gardez le HTTPS activé et supprimez les copies inutilisées du gestionnaire.
- **Un ZIP placé dans un dossier web public peut être téléchargé directement par toute personne connaissant son URL**, y compris s'il contient des fichiers de configuration sensibles. Préférez l'espace privé pour les sauvegardes.
- Les contrôles d'archives et les filtres d'extensions **ne sont pas un antivirus**. N'extrayez que des fichiers fiables ; une archive ZIP du site **n'inclut pas la base de données** sauf export séparé.
- **Erreur 500 :** consultez le journal d'erreurs PHP. **Import impossible :** vérifiez `upload_max_filesize` et `post_max_size`. **Extraction impossible :** vérifiez les permissions, l'espace disponible, les limites de taille/temps et les conflits. **ZIP introuvable :** vérifiez sa présence dans la racine autorisée ou dans les archives privées.
- Si une méthode `ZipArchive` manque sur le serveur, utilisez une version compatible et à jour du gestionnaire ; la version PHP ne garantit pas à elle seule la disponibilité de toutes les méthodes ZIP.

**Auteur :** Mohieddine ABASSI · [mon site web](https://portfolio.mohiedineabassi.workers.dev/) · **Copyright © 2026** · **Mention de licence :** MIT .
