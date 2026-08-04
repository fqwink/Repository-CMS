# AGENTS.md

## 作業開始時の必須確認

- すべての作業開始時に、必ずこの `AGENTS.md` を読むこと。
- このファイルの内容は、このリポジトリ内での作業ルールとして最優先で扱うこと。
- `AGENTS.md` を読んだ後、以下のドキュメント関連ファイル一覧に明記されたファイルをすべて読むこと。
- ドキュメント関連ファイルを読めない場合は、作業を開始せず、読めない理由をユーザーへ報告すること。

## ドキュメント関連ファイル一覧

- `Docs/Project_Charter`
- `Docs/Development_Plan`
- `Docs/Brand_Color_Spec`
- `Docs/RepositoryCMS/Master_Design`
- `Docs/RepositoryCMS/Change_History`
- `Docs/ServerSideLogicFramework/Master_Design`
- `Docs/ServerSideLogicFramework/Change_History`

## 基本方針

- 既存の仕様・設計・ファイル構成を尊重すること。
- ユーザーの明示指示がない限り、不要な仕様変更・大規模リファクタリング・削除を行わないこと。
- TypeScript/JavaScript製移行計画は、`Docs/Development_Plan` に基づき v.0.18 以降から段階的に進めること。
- TypeScript/JavaScript製移行中も、ServerSideLogicFramework準拠、Repository CMS本体との責務分離、運用適合確認を維持すること。
- `ServerSideLogicFramework/` は同一リポジトリ内で恒久管理し、別リポジトリへ分割しないこと。
- Repository CMS本体は、`ServerSideLogicFramework/` の仕様・機能に準拠して開発すること。
- サーバーサイドロジックフレームワーク本体は `ServerSideLogicFramework/ServerSideLogicFramework.php` の単一ファイル原則とし、Repository CMSのCMS Core開発集約対象へ実体を残さないこと。
- ServerSideLogicFramework本体正本はCMS Coreへ正本コピーとして実行集約し、`RepositoryCMS/Core/ServerSideLogicFramework.php` に配置すること。
- ServerSideLogicFrameworkクライアントツール正本は `ServerSideLogicFramework/ServerSideLogicFrameworkClient.php` の単一ファイル原則とし、Repository CMSは正本コピーを `RepositoryCMS/Core/ServerSideLogicFrameworkClient.php` としてCoreへ自動集約すること。
- Repository CMSはServerSideLogicFrameworkとの連携を内部API経由方式とし、ServerSideLogicFrameworkクライアントツールを組み込まない場合は動作不可とすること。
- Repository CMSはServerSideLogicFramework本体正本コピーをCMS Coreへ実行集約し、`RepositoryCMS/Core/ServerSideLogicFramework.php` として配置しない場合も動作不可とすること。
- 特定目的型責務以外のRepository CMS開発は、すべて `RepositoryCMS/Core/` の開発集約で管理すること。
- CMS Coreは、開発集約と実行集約の両方を持つ領域とすること。
- `RepositoryCMS/Core/` の開発集約は特定目的型責務以外の開発を扱い、実行集約は実行、テスト、リリース、アップデート用の集約を扱うこと。
- ServerSideLogicFramework本体およびクライアントツールは、ServerSideLogicFramework開発元のみが開発、保守、メンテナンス、配布できること。
- すべてのドキュメントは、リポジトリルート直下の `Docs/` で管理すること。
- プロジェクト憲章およびマスター仕様は `Docs/Project_Charter` に集約すること。
- 開発計画案は `Docs/Development_Plan` に一元管理し、バージョンごとの計画は5行以内で簡潔にまとめること。
- ブランドカラー仕様は `Docs/Brand_Color_Spec` で管理すること。
- Repository CMS本体関連ドキュメントは `Docs/RepositoryCMS/` で管理すること。
- サーバーサイドロジックフレームワーク関連ドキュメントは `Docs/ServerSideLogicFramework/` で管理すること。
- `Docs/Project_Charter` の修正・変更・削除は、必ず事前にユーザーの承認を得てから行うこと。
- `Docs/Development_Plan` の修正・変更・削除は、必ず事前にユーザーの承認を得てから行うこと。
- `Docs/RepositoryCMS/Master_Design` の修正・変更・削除は、必ず事前にユーザーの承認を得てから行うこと。
- `Docs/ServerSideLogicFramework/Master_Design` の修正・変更・削除は、必ず事前にユーザーの承認を得てから行うこと。
- 判断に迷う変更は、実装前にユーザーへ確認すること。

## 仕様・実装フロー

- 仕様変更・機能追加・設計方針変更を行う場合は、まず `Docs/Project_Charter` に記載する仕様方針を提案すること。
- 開発計画案を変更する場合は、まず `Docs/Development_Plan` に記載する計画方針を提案すること。
- ユーザー承認を得るまで、`Docs/Project_Charter` と `Docs/Development_Plan` を更新・修正・削除しないこと。
- ユーザー承認後に、承認された仕様方針を `Docs/Project_Charter` へ明記すること。
- ユーザー承認後に、承認された開発計画方針を `Docs/Development_Plan` へ明記すること。
- `Docs/Project_Charter` を確定してから、対象に応じた `Docs/RepositoryCMS/Master_Design` または `Docs/ServerSideLogicFramework/Master_Design` に記載する設計方針を提案すること。
- ユーザー承認を得るまで、対象の `Master_Design` を更新・修正・削除しないこと。
- ユーザー承認後に、承認された設計方針を対象の `Master_Design` へ明記すること。
- 実装は、`Docs/Project_Charter`、`Docs/Development_Plan`、対象の `Master_Design` の確定後に行うこと。
- 対象の `Master_Design` 確定後、実装開始前に必ずユーザーから実装承認を得ること。
- 実装承認を得るまで、実装コードの追加・修正・削除を行わないこと。
- 実装は、必ず `Docs/Project_Charter`、`Docs/Development_Plan`、対象の `Master_Design` に明記された内容通りに行うこと。
- 実装後は、確認可能な範囲でバグ修正を繰り返し、バグ修正ゼロ化を目指すこと。
- 実装とバグ修正ゼロ化が完了してから、対象の `Change_History` を更新すること。
- `Change_History` には、バージョンごとの変更履歴を3行以内で簡潔に明記すること。

## データ保全

- データ保全を最優先すること。
- 作業データ・保存データ・生成物を削除する場合は、削除してよい根拠を確認してから行うこと。
- 保全状態が不明な場合は、安全側に倒し、処理を止めること。

## セキュリティ

- 認証情報・トークン・秘密鍵・パスワードをGit管理対象にしないこと。
- 外部依存を追加する場合は、必要性を明確にすること。
- `RepositoryCMS/Core/app.php` 以外への直接アクセスを前提にした設計を行わないこと。

## 実装ルール

- HTTPエントリーポイントは `RepositoryCMS/Core/app.php` を前提とすること。
- 初期版では、`Docs/Project_Charter` に記載された「将来機能」を実装しないこと。
- 将来機能は、先に `Docs/Project_Charter` へ仕様を明記し、ユーザー承認を得てから開発すること。
- 許可されたコンテンツ拡張子は `.md`, `.json`, `.png`, `.svg` のみとすること。

## 開発元アップデート

- 承認済み構成方針では、リポジトリルート直下は `Docs/`、`RepositoryCMS/`、`ServerSideLogicFramework/`、特定目的型モジュラーコンポーネントを基本系統とすること。
- Repository CMS本体は `RepositoryCMS/` で管理し、CMS本体カウント対象フォルダは `RepositoryCMS/Core/`、`RepositoryCMS/Work/` の最大2フォルダとすること。
- `Docs/` は全体ドキュメント領域であり、CMS本体カウント対象外とすること。
- `ServerSideLogicFramework/` はRepository CMS本体とは別責務の並行開発領域とし、同一リポジトリ内で開発コスト削減と開発効率化を優先して管理すること。
- `StaticGenerator/`、`EditorSystem/`、`AdminFrontend/` は特定目的型モジュラーコンポーネント候補として個別正本管理すること。
- `ServerSideLogicFramework/` を別リポジトリへ分割する計画は廃止すること。
- 現在のRepository CMSを活用し、サーバーサイドロジックフレームワーク部分と今後のCMS部分を段階的に分離して、フレームワーク安定版ができるまで進めること。
- 承認済み構成方針では、`RepositoryCMS/Core/` 直下フォルダは最大3フォルダとし、現行方針は `RepositoryCMS/Core/Config/`、`RepositoryCMS/Core/Lang/`、`RepositoryCMS/Core/Themes/` の3フォルダとすること。
- 開発元のアップデート更新対象は `RepositoryCMS/Core/app.php`、`RepositoryCMS/Core/.htaccess`、`RepositoryCMS/Core/ServerSideLogicFramework.php`、`RepositoryCMS/Core/ServerSideLogicFrameworkClient.php`、`RepositoryCMS/Core/Lang/`、`RepositoryCMS/Core/Themes/` のCMS Core実行集約済み実行物のみとすること。
- アップデートリリースのマニフェストパスはCMSルート相対とし、`Core/app.php`、`Core/.htaccess`、`Core/ServerSideLogicFramework.php`、`Core/ServerSideLogicFrameworkClient.php`、`Core/Lang/`、`Core/Themes/` を使うこと。
- 開発元は特定目的型責務の開発正本を更新、改良、バグ修正し、正本コピーまたは生成物を `RepositoryCMS/Core/` へ自動集約すること。
- エンドユーザーへ `RepositoryCMS/Core/app.php`、`RepositoryCMS/Core/ServerSideLogicFramework.php`、`RepositoryCMS/Core/ServerSideLogicFrameworkClient.php`、`RepositoryCMS/Core/Lang/`、`RepositoryCMS/Core/Themes/` の変更、修正、カスタマイズ権限を与えないこと。
- テーマ関連ソースコードは `RepositoryCMS/Core/Themes/` で開発元管理とし、アップデート時に上書きされる前提とすること。
- ユーザーによるテーマ関連ソースコードの修正・カスタマイズを前提にしないこと。
- 多言語化データは `RepositoryCMS/Core/Lang/` で開発元管理とし、アップデート時に上書きされる前提とすること。
- `RepositoryCMS/Core/Config/` は保護設定領域とし、アップデート時に上書き、削除、初期化しないこと。
- `RepositoryCMS/Core/Config/` 直下にサブフォルダを作成しないこと。
- `RepositoryCMS/Core/Config/` には認証情報、ログイン失敗状態、CMSロック状態、CMS状態、ユーザーテーマ設定を直下ファイルとして保存すること。
- 開発元アップデートは `ServerSideLogicFramework/`、`StaticGenerator/`、`EditorSystem/`、`AdminFrontend/`、`RepositoryCMS/Core/Config/`、`RepositoryCMS/Core/App/`、`RepositoryCMS/Modules/`、`RepositoryCMS/Work/`、`Docs/`、コンテンツデータ、公開成果物、運用履歴、特定目的型責務の開発正本、TypeScript正本に関与しないこと。
- `RepositoryCMS/Core/App/` は廃止方針とし、作成・維持しないこと。
- 開発元公認セカンドパーティーモジュール計画は、優先事項の大幅な変更に伴い凍結とし、`RepositoryCMS/Modules/` を作成・維持しないこと。
- 開発元公認セカンドパーティーモジュール計画は廃止ではなく凍結であり、将来的に復帰する場合は、先にマスター仕様へ復帰方針、責務境界、配置、権限、アップデート対象可否を明記し、承認後に設計・実装すること。
- `RepositoryCMS/Core/Data/` は廃止方針とし、作成・維持しないこと。
- 作業データを `RepositoryCMS/Core/` 直下に作成・維持しないこと。
- 作業データは `RepositoryCMS/Work/` のみで扱い、開発元アップデート対象にしないこと。

## 変更後確認

- 変更後は、可能な範囲で構文確認・動作確認・差分確認を行うこと。
- 確認できなかった項目がある場合は、完了報告時に明記すること。
