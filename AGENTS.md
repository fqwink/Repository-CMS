# AGENTS.md

## 作業開始時の必須確認

- すべての作業開始時に、必ずこの `AGENTS.md` を読むこと。
- このファイルの内容は、`Adlaire-Ecosystem` 開発正本および公式配布物リポジトリ内での作業ルールとして最優先で扱うこと。
- `AGENTS.md` を読んだ後、ドキュメント正本リポジトリ `Adlaire-Docs` の `AGENTS.md` と対象ドキュメントを読むこと。
- `Adlaire-Docs` をローカルで参照できない場合は、公開リポジトリ `https://github.com/fqwink/Adlaire-Docs` の正本ドキュメントを参照すること。
- ドキュメント正本を読めない場合は、作業を開始せず、読めない理由をユーザーへ報告すること。

## ドキュメント正本

- ドキュメント正本リポジトリは `Adlaire-Docs` とする。
- このリポジトリ内に旧 `Docs/` を作成、維持しないこと。
- 新規ドキュメント正本をこのリポジトリに作成、維持しないこと。
- プロジェクト憲章、マスター仕様、設計、開発計画、変更履歴、ブランド、セキュリティ、データ保全、リリース方針は `Adlaire-Docs` を正本とする。
- 仕様、設計、開発計画、変更履歴の変更は、`Adlaire-Docs` の承認ルールに従うこと。

## 基本方針

- 既存の仕様・設計・ファイル構成を尊重すること。
- ユーザーの明示指示がない限り、不要な仕様変更・大規模リファクタリング・削除を行わないこと。
- `Adlaire-Ecosystem` は、Adlaireグループの開発正本および公式配布物リポジトリとする。
- 開発正本と公式配布物はリポジトリ分離せず、フォルダ単位で分離すること。
- 開発は `Source/`、公式配布物は `Release/`、生成・同期・検証は `Tools/`、一時作業は `Work/` で扱うこと。
- `Release/` を直接開発してはならない。必ず `Source/` から正本コピーまたは生成して集約すること。
- Repository CMSは、Adlaire Ecosystemの第1適用プロジェクトとして扱うこと。

## リポジトリ構成

```text
Adlaire-Ecosystem/
  AGENTS.md
  README.md
  Source/
    RepositoryCMS/
    ServerSideLogicFramework/
    AdminFrontend/
    StaticGenerator/
    EditorSystem/
  Release/
    RepositoryCMS/
    Packages/
    Manifests/
    Checksums/
  Tools/
    release-check/
    build/
    sync/
  Work/
```

- `Source/RepositoryCMS/` はRepository CMS本体の開発正本領域とする。
- `Source/ServerSideLogicFramework/` はServerSideLogicFramework本体およびクライアントツールの開発正本領域とする。
- `Source/AdminFrontend/`、`Source/StaticGenerator/`、`Source/EditorSystem/` は特定目的型責務の開発正本領域とする。
- `Release/RepositoryCMS/` はRepository CMS利用者向け公式配布物領域とする。
- `Release/Packages/`、`Release/Manifests/`、`Release/Checksums/` は配布パッケージ、マニフェスト、チェックサムを管理する。

## 仕様・実装フロー

- 仕様変更・機能追加・設計方針変更を行う場合は、まず `Adlaire-Docs` の対象仕様方針を提案すること。
- 開発計画案を変更する場合は、まず `Adlaire-Docs/Development_Plan` に記載する計画方針を提案すること。
- ユーザー承認を得るまで、`Adlaire-Docs` の仕様、設計、開発計画、変更履歴を更新・修正・削除しないこと。
- 対象のマスター設計確定後、実装開始前に必ずユーザーから実装承認を得ること。
- 実装承認を得るまで、実装コードの追加・修正・削除を行わないこと。
- 実装は、必ず `Adlaire-Docs` の確定済み仕様、開発計画、対象マスター設計に明記された内容通りに行うこと。
- 実装後は、確認可能な範囲でバグ修正を繰り返し、バグ修正ゼロ化を目指すこと。
- 実装とバグ修正ゼロ化が完了してから、`Adlaire-Docs` の対象変更履歴を更新すること。

## データ保全

- データ保全を最優先すること。
- 作業データ・保存データ・生成物を削除する場合は、削除してよい根拠を確認してから行うこと。
- 保全状態が不明な場合は、安全側に倒し、処理を止めること。
- `Work/` は一時作業領域であり、保全確認後に削除対象とする。
- 利用者データ資産、認証情報、運用データ、公開成果物を開発元アップデートで上書き、削除、初期化しないこと。

## セキュリティ

- 認証情報・トークン・秘密鍵・パスワードをGit管理対象にしないこと。
- 外部依存を追加する場合は、必要性を明確にすること。
- Repository CMSのHTTPエントリーポイントは `Source/RepositoryCMS/Core/app.php` を前提とすること。
- `Source/RepositoryCMS/Core/app.php` 以外への直接アクセスを前提にした設計を行わないこと。

## 実装ルール

- Repository CMSのCore実装は `Source/RepositoryCMS/Core/` で管理すること。
- ServerSideLogicFramework本体正本は `Source/ServerSideLogicFramework/ServerSideLogicFramework.php` の単一ファイル原則とすること。
- ServerSideLogicFrameworkクライアントツール正本は `Source/ServerSideLogicFramework/ServerSideLogicFrameworkClient.php` の単一ファイル原則とすること。
- ServerSideLogicFramework本体正本コピーは `Source/RepositoryCMS/Core/ServerSideLogicFramework.php` へ実行集約すること。
- ServerSideLogicFrameworkクライアントツール正本コピーは `Source/RepositoryCMS/Core/ServerSideLogicFrameworkClient.php` へ実行集約すること。
- TypeScript正本は `Source/AdminFrontend/`、`Source/StaticGenerator/` などの特定目的型責務領域で管理すること。
- 生成済みJavaScriptのみを `Source/RepositoryCMS/Core/` と `Release/RepositoryCMS/Core/` へ集約すること。
- Node.js、Deno、npm、CDN、外部ビルド環境を必須化しないこと。
- 許可されたコンテンツ拡張子は `.md`, `.json`, `.png`, `.svg` のみとすること。

## リリースルール

- 公式配布物は `Release/` で管理すること。
- `Release/RepositoryCMS/` には利用者向けに必要な実行物のみを含めること。
- `Release/RepositoryCMS/` にTypeScript正本、開発用設定、テスト専用ファイル、認証情報、利用者データ資産を含めてはならない。
- Repository CMS配布物には `Core/app.php`、`Core/.htaccess`、`Core/admin-frontend.js`、`Core/static-generator.js`、`Core/ServerSideLogicFramework.php`、`Core/ServerSideLogicFrameworkClient.php`、`Core/Lang/`、`Core/Themes/` を含めること。
- `Core/Config/` と `Work/` は利用者環境側の保護領域であり、配布物へ含めないこと。
- リリース前に `Tools/release-check/release-check.sh` を実行すること。

## 変更後確認

- 変更後は、可能な範囲で構文確認・動作確認・差分確認を行うこと。
- 確認できなかった項目がある場合は、完了報告時に明記すること。
