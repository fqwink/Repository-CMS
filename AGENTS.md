# AGENTS.md

## 作業開始時の必須確認

- すべての作業開始時に、必ずこの `AGENTS.md` を読むこと。
- このファイルの内容は、このリポジトリ内での作業ルールとして最優先で扱うこと。
- `AGENTS.md` を読んだ後、以下のドキュメント関連ファイル一覧に明記されたファイルをすべて読むこと。
- ドキュメント関連ファイルを読めない場合は、作業を開始せず、読めない理由をユーザーへ報告すること。

## ドキュメント関連ファイル一覧

- `RepositoryCMS/Docs/Master_Spec`
- `RepositoryCMS/Docs/Master_Design`
- `RepositoryCMS/Docs/Brand_Color_Spec`
- `RepositoryCMS/Docs/Change_History`
- `ServerSideLogicFramework/Docs/Master_Spec`
- `ServerSideLogicFramework/Docs/Master_Design`
- `ServerSideLogicFramework/Docs/Change_History`

## 基本方針

- 既存の仕様・設計・ファイル構成を尊重すること。
- ユーザーの明示指示がない限り、不要な仕様変更・大規模リファクタリング・削除を行わないこと。
- TypeScript/JavaScript製移行計画および凍結中の開発計画案は、サーバーサイドロジックフレームワークの強化とRepository CMS本体への運用適合確認が完了するまで再開しないこと。
- 当面は、ServerSideLogicFramework原型作成、強化、安定版作成、Repository CMS本体との責務分離、運用適合確認を最優先すること。
- `ServerSideLogicFramework/` は同一リポジトリ内で恒久管理し、別リポジトリへ分割しないこと。
- Repository CMS本体は、`ServerSideLogicFramework/` の仕様・機能に準拠して開発すること。
- サーバーサイドロジックフレームワーク関係の実装コードは `ServerSideLogicFramework/ServerSideLogicFramework.php` の単一ファイル原則とし、Repository CMS本体側へ実体を残さないこと。
- サーバーサイドロジックフレームワーク関連ドキュメントは `ServerSideLogicFramework/Docs/` で管理し、Repository CMS本体関連ドキュメントは `RepositoryCMS/Docs/` で管理すること。
- `RepositoryCMS/Docs/Master_Spec` の修正・変更・削除は、必ず事前にユーザーの承認を得てから行うこと。
- `RepositoryCMS/Docs/Master_Design` の修正・変更・削除は、必ず事前にユーザーの承認を得てから行うこと。
- 判断に迷う変更は、実装前にユーザーへ確認すること。

## 仕様・実装フロー

- 仕様変更・機能追加・設計方針変更を行う場合は、まず `RepositoryCMS/Docs/Master_Spec` に記載する仕様方針を提案すること。
- ユーザー承認を得るまで、`RepositoryCMS/Docs/Master_Spec` を更新・修正・削除しないこと。
- ユーザー承認後に、承認された仕様方針を `RepositoryCMS/Docs/Master_Spec` へ明記すること。
- `RepositoryCMS/Docs/Master_Spec` を確定してから、`RepositoryCMS/Docs/Master_Design` に記載する設計方針を提案すること。
- ユーザー承認を得るまで、`RepositoryCMS/Docs/Master_Design` を更新・修正・削除しないこと。
- ユーザー承認後に、承認された設計方針を `RepositoryCMS/Docs/Master_Design` へ明記すること。
- 実装は、`RepositoryCMS/Docs/Master_Spec` と `RepositoryCMS/Docs/Master_Design` の確定後に行うこと。
- `RepositoryCMS/Docs/Master_Design` 確定後、実装開始前に必ずユーザーから実装承認を得ること。
- 実装承認を得るまで、実装コードの追加・修正・削除を行わないこと。
- 実装は、必ず `RepositoryCMS/Docs/Master_Spec` と `RepositoryCMS/Docs/Master_Design` に明記された内容通りに行うこと。
- 実装後は、確認可能な範囲でバグ修正を繰り返し、バグ修正ゼロ化を目指すこと。
- 実装とバグ修正ゼロ化が完了してから、`RepositoryCMS/Docs/Change_History` を更新すること。
- `RepositoryCMS/Docs/Change_History` には、バージョンごとの変更履歴を3行以内で簡潔に明記すること。

## データ保全

- データ保全を最優先すること。
- 作業データ・保存データ・生成物を削除する場合は、削除してよい根拠を確認してから行うこと。
- 保全状態が不明な場合は、安全側に倒し、処理を止めること。

## セキュリティ

- 認証情報・トークン・秘密鍵・パスワードをGit管理対象にしないこと。
- 外部依存を追加する場合は、必要性を明確にすること。
- RepositoryCMS/Core・RepositoryCMS/Modules への直接アクセスを前提にした設計を行わないこと。

## 実装ルール

- HTTPエントリーポイントは `RepositoryCMS/Core/app.php` を前提とすること。
- 初期版では、マスター仕様書に記載された「将来機能」を実装しないこと。
- 将来機能は、先に `RepositoryCMS/Docs/Master_Spec` へ仕様を明記し、ユーザー承認を得てから開発すること。
- 許可されたコンテンツ拡張子は `.md`, `.json`, `.png`, `.svg` のみとすること。

## 開発元アップデート

- 承認済み構成方針では、リポジトリルート直下は `RepositoryCMS/` と `ServerSideLogicFramework/` の2系統を基本とすること。
- Repository CMS本体は `RepositoryCMS/` で管理し、CMS本体カウント対象フォルダは `RepositoryCMS/Core/`、`RepositoryCMS/Modules/`、`RepositoryCMS/Work/` の最大3フォルダとすること。
- `RepositoryCMS/Docs/` はCMS本体ドキュメント領域であり、CMS本体カウント対象外とすること。
- `ServerSideLogicFramework/` はRepository CMS本体とは別責務の並行開発領域とし、同一リポジトリ内で開発コスト削減と開発効率化を優先して管理すること。
- `ServerSideLogicFramework/` を別リポジトリへ分割する計画は廃止すること。
- 現在のRepository CMSを活用し、サーバーサイドロジックフレームワーク部分と今後のCMS部分を段階的に分離して、フレームワーク安定版ができるまで進めること。
- 承認済み構成方針では、`RepositoryCMS/Core/` 直下フォルダは最大7フォルダとし、現行方針は `RepositoryCMS/Core/App/`、`RepositoryCMS/Core/Config/` の2フォルダとすること。
- 開発元のアップデート更新対象は `RepositoryCMS/Core/app.php`、`RepositoryCMS/Core/.htaccess`、`RepositoryCMS/Core/App/` のみとすること。
- アップデートリリースのマニフェストパスはCMSルート相対とし、`Core/app.php`、`Core/.htaccess`、`Core/App/` を使うこと。
- 開発元は `RepositoryCMS/Core/App/` 直下を更新、改良、バグ修正できること。
- エンドユーザーへ `RepositoryCMS/Core/App/` 直下の変更、修正、カスタマイズ権限を与えないこと。
- テーマ関連ソースコードは `RepositoryCMS/Core/App/Themes/` で開発元管理とし、アップデート時に上書きされる前提とすること。
- ユーザーによるテーマ関連ソースコードの修正・カスタマイズを前提にしないこと。
- 多言語化データは `RepositoryCMS/Core/App/Lang/` で開発元管理とし、アップデート時に上書きされる前提とすること。
- `RepositoryCMS/Core/Config/` は保護設定領域とし、アップデート時に上書き、削除、初期化しないこと。
- `RepositoryCMS/Core/Config/` 直下にサブフォルダを作成しないこと。
- `RepositoryCMS/Core/Config/` には認証情報、ログイン失敗状態、CMSロック状態、CMS状態、ユーザーテーマ設定を直下ファイルとして保存すること。
- 開発元は `RepositoryCMS/Core/Config/`、`RepositoryCMS/Modules/`、`RepositoryCMS/Work/`、`RepositoryCMS/Docs/`、コンテンツデータ、公開成果物、運用履歴に関与しないこと。
- `RepositoryCMS/Core/Data/` は廃止方針とし、作成・維持しないこと。
- 作業データを `RepositoryCMS/Core/` 直下に作成・維持しないこと。
- 作業データは `RepositoryCMS/Work/` のみで扱い、開発元アップデート対象にしないこと。

## 変更後確認

- 変更後は、可能な範囲で構文確認・動作確認・差分確認を行うこと。
- 確認できなかった項目がある場合は、完了報告時に明記すること。
