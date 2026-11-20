import { cpSync, existsSync, mkdirSync, rmSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const root = resolve(dirname(fileURLToPath(import.meta.url)), "..");
const builtPublic = resolve(root, "dist");
const packageRoot = resolve(root, "dist-cpanel");
const publicRoot = resolve(packageRoot, "public");
const privateRoot = resolve(packageRoot, "private");
const databaseRoot = resolve(packageRoot, "database");

const requiredBuildFiles = ["index.html", "assets"];
for (const name of requiredBuildFiles) {
  if (!existsSync(resolve(builtPublic, name))) {
    throw new Error(`Missing static build output: dist/${name}. Run npm run build:cpanel first.`);
  }
}

if (existsSync(resolve(privateRoot, "config.php"))) {
  throw new Error("Refusing to overwrite dist-cpanel/private/config.php; move the private config out before packaging.");
}

rmSync(packageRoot, { recursive: true, force: true });
mkdirSync(publicRoot, { recursive: true });
mkdirSync(privateRoot, { recursive: true });
mkdirSync(databaseRoot, { recursive: true });

cpSync(builtPublic, publicRoot, { recursive: true });
cpSync(resolve(root, "php-backend/public/.htaccess"), resolve(publicRoot, ".htaccess"));
mkdirSync(resolve(publicRoot, "uploads"), { recursive: true });
cpSync(resolve(root, "php-backend/public/uploads/.htaccess"), resolve(publicRoot, "uploads/.htaccess"));
cpSync(resolve(root, "php-backend/public/api"), resolve(publicRoot, "api"), { recursive: true });
cpSync(resolve(root, "php-backend/private/.htaccess"), resolve(privateRoot, ".htaccess"));
for (const helper of ["birthday-sms.php", "birthday-sms-inbox.php"]) {
  cpSync(
    resolve(root, "php-backend/private", helper),
    resolve(privateRoot, helper),
  );
}
cpSync(
  resolve(root, "php-backend/private/config.example.php"),
  resolve(privateRoot, "config.example.php"),
);
cpSync(resolve(root, "php-backend/schema.sql"), resolve(databaseRoot, "schema.sql"));
cpSync(resolve(root, "php-backend/migrations"), resolve(databaseRoot, "migrations"), {
  recursive: true,
});
cpSync(resolve(root, "php-backend/README-FA.md"), resolve(packageRoot, "README-FA.md"));

const guide = [
  "# بسته استقرار 3Food POS روی cPanel",
  "",
  "## ساختار بسته",
  "",
  "- `public/`: فایل‌های React استاتیک، `.htaccess` و PHP API",
  "- `private/`: بیرون Document Root؛ شامل نمونه تنظیمات خصوصی",
  "- `database/`: schema و migrationهای MySQL",
  "",
  "## نصب",
  "",
  "1. در cPanel یک دیتابیس MySQL و کاربر اختصاصی بسازید.",
  "2. `database/schema.sql` را در دیتابیس خالی وارد کنید. برای دیتابیس موجود، migrationها را به‌ترتیب شماره و فقط یک‌بار اجرا کنید.",
  "3. پوشه `public` را Document Root زیردامنه قرار دهید؛ `private` باید sibling آن و خارج از Document Root بماند.",
  "4. `private/config.example.php` را به `private/config.php` کپی کنید و اطلاعات DB، `app_key` و `setup_token` تصادفی را در آن وارد کنید. از commit یا قرار دادن این فایل در پوشه public خودداری کنید.",
  "5. SSL را فعال کنید و سپس `/api/v1/health` و `/api/v1/preflight` را بررسی کنید؛ مقدار `production_ready` باید true باشد.",
  "6. از صفحه ورود، مدیر اولیه را با setup token بسازید؛ سپس setup token را از config خصوصی حذف کنید.",
  "7. تست سفارش و نقش‌ها را روی staging انجام دهید. پیامک فقط پس از واردکردن credential در config خصوصی و آزمایش واقعی فعال شود.",
  "",
  "این بسته هیچ `config.php` واقعی یا رمز دیتابیس ندارد. preflight فقط پیش‌نیازهای فنی را می‌سنجد و build موفق به معنی تأیید سفارش واقعی، نقش‌ها، پیامک یا پذیرش نهایی فروشگاه نیست.",
].join(String.fromCharCode(10));

writeFileSync(resolve(packageRoot, "DEPLOYMENT-FA.md"), guide, "utf8");

const manifest = {
  staticEntry: "public/index.html",
  apiEntry: "public/api/index.php",
  privateConfigTemplate: "private/config.example.php",
  schema: "database/schema.sql",
};
writeFileSync(resolve(packageRoot, "package-manifest.json"), JSON.stringify(manifest, null, 2) + "\n");

const packagedFiles = [
  "public/index.html",
  "public/.htaccess",
  "public/api/index.php",
  "private/.htaccess",
  "private/config.example.php",
  "private/birthday-sms.php",
  "private/birthday-sms-inbox.php",
  "database/schema.sql",
  "DEPLOYMENT-FA.md",
];
for (const relativePath of packagedFiles) {
  if (!existsSync(resolve(packageRoot, relativePath))) {
    throw new Error(`Incomplete cPanel package; missing ${relativePath}`);
  }
}
if (existsSync(resolve(privateRoot, "config.php"))) {
  throw new Error("A real private config must never be included in the generated package.");
}
console.log("cPanel package created and validated at dist-cpanel/");
