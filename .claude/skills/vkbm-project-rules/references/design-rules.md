# 共通デザイン実装ルール

## スタイルガイドの参照
- UI の実装はフロント側は基本的に `docs/ui/style-guide.html` 、管理画面側は `docs/ui/style-guide-admin.html` に準拠する。

## 1. CSS 変数の管理
- グローバルなカラーパレット・余白・角丸などのデザイントークンは `assets/scss/variables.scss` に `--vkbm--*` 形式で定義する。ビルド後のCSSは `build/assets/css/` に出力する。
- SCSS から `@use '../../../assets/scss/index-blocks.scss' as *;`（相対パスはファイル位置に応じて調整）を利用し、ブロック側の共通スタイルをまとめて読み込む。新しい変数を導入する場合は `assets/scss/variables.scss` に追記し、`assets/scss/index.scss` と `assets/scss/index-blocks.scss` から公開する。
- 予約ブロック、認証フォーム、メニューカードなど共通 UI では、既存の `--vkbm--color--border`, `--vkbm--radius--md`, `--vkbm--spacing--lg` などを使って統一感を担保する。
- テーマ側でカスタムするときにも `:root` で `--vkbm--*` を上書きすれば全体が追随する、という前提で設計する。
- **プライマリカラーの優先順位**：
  - 基本設定（デザイン）で指定した値を最優先する。
  - 未指定の場合は PHP 側で `--vkbm--color--primary` を出力しない。
  - テーマ側で `--wp--preset--color--primary` が指定されている場合、CSS 側のフォールバックでそれが適用される。
- **予約に進むボタンの色**：
  - 基本設定（デザイン）で指定した値を `--vkbm--color--reservation-action` に反映する。
  - 未指定の場合は `--vkbm--color--primary` を利用する。

## 2. アラートコンポーネント `vkbm-alert`
- ガイド／通知／エラー／ロード中メッセージなど視覚的なアラートは、必ず `vkbm-alert` クラスを用いた汎用コンポーネントで表示する。
- 基本構造：
  ```html
  <div class="vkbm-alert vkbm-alert__info" role="status">メッセージ</div>
  ```
  - `vkbm-alert__info`：情報／進捗表示。`role="status"` を推奨。
  - `vkbm-alert__warning`：注意喚起。`role="status"` または `role="alert"`。
  - `vkbm-alert__danger`：エラー／重大な警告。`role="alert"`。
  - `vkbm-alert__success`：成功通知（例：予約完了）。`role="status"` を推奨し、必要に応じて `.text-center`（`assets/scss/utility.scss` で定義）を併用して中央寄せする。
- スタイルはフォーム以外でも再利用できるよう `assets/scss/alert.scss` に集約しており、背景色・角丸（`var(--vkbm--radius--md)`）・余白も共通の CSS 変数を使う。
- ログインエラー、予約確認の API エラー、フォームのロード中表示など、文脈が違っても同一 UI を利用することでブランド一貫性を保つ。
- リスト形式で複数メッセージを出す場合は `.vkbm-alert ul` を使用する。

## 3. 共通スタイル適用のルール
- スタイルガイドに定義されている共通クラス（`vkbm-alert` / `vkbm-button` / `vkbm-buttons` / `text-center` など）で表現できる場合はそれを優先し、独自のCSSは極力増やさない。
- プラグイン内で独自の角丸や余白を設定する場合、特別な理由がない限り `--vkbm--radius--*` / `--vkbm--spacing--*` を利用する。
- コンポーネント固有のカスタマイズが必要なときも、まず共通変数で表現できるか検討し、用途が限定的な場合のみ個別変数を追加する。
- テキストカラーも `--vkbm--color--text-secondary` など既存色を優先し、必要なアクセントカラーは `--vkbm--color--accent` 系統を使用する。

## 4. 共通ボタン `vkbm-button`
- 予約画面、予約確認、メニューループ、認証フォームなどで見た目を統一するため、ボタン要素には共通クラス `vkbm-button` を付与する（既存の役割別クラスは残しつつ共通クラスを併用する）。
- サイズ（いずれかを付与）
  - `vkbm-button__sm` / `vkbm-button__md` / `vkbm-button__lg`
- 役割（いずれかを付与）
  - `vkbm-button__primary` / `vkbm-button__secondary`
  - テキストリンク風の操作は `vkbm-button__link` を使う（例：ユーザー情報編集、ログアウトなど）
- ボタングループ（横並びのボタン集合）は `vkbm-buttons` を使用し、寄せる場合は `vkbm-buttons__center` / `vkbm-buttons__right` を併用する。
- 例
  ```html
  <a class="vkbm-button vkbm-button__md vkbm-button__primary" href="/menu/123">詳細を見る</a>
  <button class="vkbm-button vkbm-button__md vkbm-button__primary" type="button">予約に進む</button>
  <button class="vkbm-button vkbm-button__sm vkbm-button__link" type="button">ユーザー情報編集</button>
  <div class="vkbm-buttons vkbm-buttons__right">
    <a class="vkbm-button vkbm-button__md vkbm-button__primary" href="/menu/123">詳細を見る</a>
    <a class="vkbm-button vkbm-button__md vkbm-button__secondary" href="/reserve?menu=123">予約に進む</a>
  </div>
  ```
