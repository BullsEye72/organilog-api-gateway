# Proxy API Organilog

[English](README.md) | **Français**

Un proxy PHP auto-hébergé et une console web pour l’API Organilog v3. Connectez
votre compte Organilog une seule fois, puis attribuez à chaque application ou
automatisation sa propre clé API, ses permissions et sa limite de requêtes.
Votre jeton Organilog reste sur votre serveur.

La console permet de parcourir les points d’accès de l’API, d’essayer des requêtes,
de consulter leur historique et de gérer les clés d’intégration. Aucune base de
données ni installation de dépendances n’est nécessaire.

[Télécharger une version](https://github.com/BullsEye72/organilog-api-gateway/releases)
· [Installer](#installer-sur-votre-serveur-web)
· [Guide d’hébergement (en anglais)](docs/administration.md)
· [Développement (en anglais)](docs/development.md)

![Console API Organilog en mode sombre, avec le catalogue des points d’accès et un exemple de requête sur un ticket](docs/screenshots/console.png)

*Parcourez l’API, modifiez vos requêtes JSON et consultez la disponibilité
d’Organilog dans une même console.*

- **Explorez l’API.** Partez des exemples de requêtes, consultez les notes de
  chaque point d’accès et copiez les requêtes sous forme de commandes curl.
- **Contrôlez chaque intégration.** Attribuez à chaque outil sa propre clé,
  ses permissions et sa limite de requêtes.
- **Suivez l’activité.** Consultez la disponibilité d’Organilog et retrouvez
  vos requêtes dans l’historique du navigateur.

## Prérequis

- Un serveur web avec **PHP 8.1 ou une version ultérieure**, les extensions
  **curl**, **mbstring** et **json**, ainsi que les sessions PHP activées.
- Un domaine ou sous-domaine avec **HTTPS**.
- Le nom de votre compte Organilog et votre jeton API.
- Un accès au gestionnaire de fichiers de votre hébergeur ou à un client FTP.

Votre hébergement doit permettre de faire pointer la **racine web du domaine**
(le dossier servi aux visiteurs) vers le dossier **`public/`** du projet. Utilisez
un domaine ou sous-domaine dédié, comme `organilog.example.com`. L’installation
dans un sous-chemin tel que `example.com/organilog` n’est pas prise en charge.

## Installer sur votre serveur web

### 1. Transférer les fichiers

Téléchargez l’archive d’installation **`organilog-vX.Y.Z.zip`** depuis une
[version publiée sur GitHub](https://github.com/BullsEye72/organilog-api-gateway/releases),
ou téléchargez ce dépôt. Extrayez l’archive, puis transférez le projet sur votre
hébergement, y compris les fichiers cachés `.htaccess`. Faites pointer la racine
web de votre domaine vers le dossier **`public/`** du projet et activez HTTPS.

Par exemple, si le projet se trouve dans `/home/you/organilog`, la racine web doit
être `/home/you/organilog/public`. Gardez les autres fichiers du projet en dehors
du dossier public.

Avec **Apache 2.4**, activez `mod_rewrite` et autorisez les règles `.htaccess`
fournies (`AllowOverride All`). Avec **Nginx**, adaptez
l’[exemple de configuration](deploy/nginx.conf). Demandez de l’aide à votre
hébergeur si nécessaire.

PHP doit pouvoir créer `config.php` et le dossier `var/` à côté de `public/`, puis
écrire dans `var/`. Il doit également pouvoir envoyer des requêtes HTTPS à
Organilog.

### 2. Lancer l’assistant d’installation

Les noms des boutons et des onglets ci-dessous correspondent à l’interface,
qui reste en anglais.

1. Ouvrez **`https://your-domain/setup.php`** dans votre navigateur, en remplaçant
   `your-domain` par votre domaine.
2. Dans le gestionnaire de fichiers de votre hébergeur ou votre client FTP,
   ouvrez **`var/setup-key.txt`**. L’assistant crée ce fichier lors de votre première
   visite. Collez le code qu’il contient dans l’assistant pour confirmer que vous
   contrôlez l’installation.
3. Choisissez un nom pour l’application et, si vous le souhaitez, un logo.
4. Saisissez le nom de votre compte Organilog et votre jeton API. Pour
   `mycompany.organilog.com`, saisissez **`mycompany`** comme nom de compte.
5. Cliquez sur **Test Organilog connection**, puis confirmez et cliquez sur
   **Install Organilog API**.
6. **Enregistrez la clé d’administration dans votre gestionnaire de mots de passe.
   Elle ne s’affiche qu’une seule fois.**

L’assistant enregistre votre configuration, puis se verrouille. S’il vous demande
de supprimer manuellement `public/setup.php`, faites-le depuis votre gestionnaire
de fichiers.

### 3. Créer votre première clé d’intégration

1. Ouvrez votre domaine, collez la clé d’administration dans le champ **API key**,
   puis cliquez sur **Connect**.
2. Ouvrez **Keys**, puis cliquez sur **New key**.
3. Donnez un nom à la clé, choisissez les permissions nécessaires et définissez sa
   limite de requêtes. Commencez par un accès en lecture aux entités utilisées par
   votre intégration.
4. Cliquez sur **Save**, puis copiez la nouvelle clé dans la configuration de votre
   intégration. Cette clé ne s’affiche également qu’une seule fois.

Utilisez une clé distincte pour chaque intégration. La clé d’administration sert à
gérer ces clés ; réservez-la aux opérations d’administration.

![Gestion des clés API avec les permissions, les limites de requêtes, l’utilisation et les actions par intégration ; les noms et préfixes des clés sont floutés](docs/screenshots/api-keys.png)

*Gérez les permissions et les limites de chaque intégration, avec un aperçu de
l’utilisation et de la dernière activité. Les noms et préfixes des clés sont
floutés sur cette capture.*

## Utiliser la console et l’API

Dans **Console**, choisissez un point d’accès dans la liste, lisez ses notes et
adaptez l’exemple de requête avec les valeurs de votre compte. Cliquez sur **Send**
pour voir la réponse, ou sur **Copy as curl** pour utiliser la requête ailleurs.
L’onglet **History** affiche les dernières requêtes effectuées depuis ce navigateur.

Vos intégrations appellent votre propre domaine et transmettent leur clé dans un
en-tête `Authorization: Bearer ...`. Par exemple, cette requête de lecture liste
les tickets :

```sh
curl 'https://organilog.example.com/v1/issue/read_all?page_nbr=1' \
  -H 'Authorization: Bearer YOUR_INTEGRATION_KEY'
```

Remplacez le domaine et `YOUR_INTEGRATION_KEY` par vos propres valeurs. Le proxy
ajoute automatiquement le compte et le jeton Organilog. Le champ `data` de la
réponse contient la réponse d’Organilog. La console fournit la documentation des
points d’accès et des exemples de requêtes.

### Autoriser les modifications dans Organilog

Les nouvelles installations autorisent uniquement la lecture. Pour permettre les
créations, modifications et suppressions, ouvrez le fichier privé **`config.php`**
et remplacez **`'allow_writes' => false`** par **`'allow_writes' => true`**. Chaque
clé d’intégration doit également disposer des permissions nécessaires pour
l’opération. Les requêtes envoyées depuis la console utilisent votre véritable
compte Organilog.

## Entretenir votre installation

- Sauvegardez **`config.php` et l’intégralité de `var/`** dans un endroit sûr.
  Ils contiennent vos identifiants, les paramètres des clés, les données
  d’utilisation et les journaux.
- Lors d’une mise à jour, conservez ces fichiers et remplacez le code source de
  l’application. Consultez les
  [instructions de mise à jour (en anglais)](docs/administration.md#updating-an-existing-installation),
  notamment la pause nécessaire lors d’une migration depuis l’ancien mécanisme
  de verrouillage des fichiers.
- Gérez, désactivez ou renouvelez vos clés d’intégration depuis **Keys**. Si vous
  perdez la clé d’administration, suivez les
  [instructions de récupération (en anglais)](docs/administration.md#configuration-and-administration).

Pour les problèmes d’hébergement, les erreurs de connexion et les autres réglages,
consultez le [guide d’hébergement et d’administration (en anglais)](docs/administration.md).
Pour travailler sur le code en local, consultez le
[guide de développement (en anglais)](docs/development.md).

## Licence et attribution

Copyright 2026 Anthony Jaspers. Le projet est distribué sous **Apache License 2.0
with the Commons Clause License Condition v1.0**. Cette licence donne accès au code
source et comporte une restriction sur la vente. Consultez [LICENSE](LICENSE)
pour les conditions complètes et [NOTICE](NOTICE) pour les attributions.
Le logiciel est fourni en l’état, sans garantie.

Il s’agit d’une intégration communautaire indépendante développée avec l’accord
d’Organilog, et non d’un produit officiel Organilog. Les services et l’API
d’Organilog restent soumis à leurs propres conditions.
