import { useEffect, useState } from "react";
import { AlertCircle, CheckCircle2, ChefHat, Plus, RefreshCw, Save } from "lucide-react";
import {
  ApiError, activateRecipeVersion, createRecipeDraftVersion, getInventoryStockItems,
  getInventoryUnits, getPosCatalogProductDetails, getPosCatalogProducts, getProductRecipe,
  type InventoryStockItem, type InventoryUnit, type PosCatalogProduct, type PosCatalogProductDetail,
  type ProductRecipe,
} from "../../shared/api/client";
import { Button, Notice, PageHeader, Surface } from "../../shared/ui";
import "./recipes.css";

type DraftLine = { stock_item_id: string; quantity: string; unit_code: string; waste_percent: string };
const statusLabels: Record<string,string> = { draft: "پیش‌نویس", active: "فعال", retired: "بایگانی‌شده" };
const format = (value: string | number) => new Intl.NumberFormat("fa-IR", { maximumFractionDigits: 6 }).format(Number(value) || 0);
const normalizeDecimalInput = (value:string) => {
  const normalized=value
    .replace(/[۰-۹]/g,digit=>String("۰۱۲۳۴۵۶۷۸۹".indexOf(digit)))
    .replace(/[٠-٩]/g,digit=>String("٠١٢٣٤٥٦٧٨٩".indexOf(digit)))
    .replace(/٫/g,".")
    .replace(/[٬،,\s]/g,"")
    .replace(/[^0-9.]/g,"");
  const [whole,...fraction]=normalized.split(".");
  return fraction.length? `${whole}.${fraction.join("")}`:whole;
};

export function RecipeWorkspace() {
  const [products,setProducts]=useState<PosCatalogProduct[]>([]);
  const [product,setProduct]=useState<PosCatalogProductDetail|null>(null);
  const [stockItems,setStockItems]=useState<InventoryStockItem[]>([]);
  const [units,setUnits]=useState<InventoryUnit[]>([]);
  const [recipe,setRecipe]=useState<ProductRecipe|null>(null);
  const [productId,setProductId]=useState("");
  const [variantId,setVariantId]=useState("");
  const [recipeName,setRecipeName]=useState("");
  const [lines,setLines]=useState<DraftLine[]>([{stock_item_id:"",quantity:"1",unit_code:"",waste_percent:"0"}]);
  const [loading,setLoading]=useState(true);
  const [saving,setSaving]=useState(false);
  const [error,setError]=useState<string|null>(null);
  const [notice,setNotice]=useState<string|null>(null);

  async function loadBase() {
    setLoading(true); setError(null);
    try {
      const [catalog,items,unitList]=await Promise.all([
        getPosCatalogProducts(),getInventoryStockItems("",false),getInventoryUnits(),
      ]);
      setProducts(catalog);setStockItems(items);setUnits(unitList);
      setProductId(current=>catalog.some(p=>p.id===current)?current:catalog[0]?.id??"");
    } catch(e) { setError(e instanceof ApiError?e.message:"بارگذاری اطلاعات رسپی انجام نشد."); }
    finally { setLoading(false); }
  }
  useEffect(()=>{void loadBase();},[]);

  useEffect(()=>{
    let alive=true;
    if(!productId){setProduct(null);setRecipe(null);return;}
    setError(null);
    Promise.all([getPosCatalogProductDetails(productId),getProductRecipe(productId,variantId||undefined)])
      .then(([detail,current])=>{if(alive){setProduct(detail);setRecipe(current);setRecipeName(current?.name??`رسپی ${detail.name}`);}})
      .catch(e=>{if(alive)setError(e instanceof ApiError?e.message:"دریافت رسپی محصول انجام نشد.");});
    return ()=>{alive=false;};
  },[productId,variantId]);

  useEffect(()=>{
    if(!product)return;
    const available=product.variants.filter(v=>v.is_active);
    if(variantId && !available.some(v=>v.id===variantId))setVariantId("");
  },[product,variantId]);

  function updateLine(index:number,patch:Partial<DraftLine>) {
    setLines(old=>old.map((line,i)=>i===index?{...line,...patch}:line));
  }
  function addLine() {
    if(lines.length>=100)return;
    setLines(old=>[...old,{stock_item_id:"",quantity:"1",unit_code:"",waste_percent:"0"}]);
  }
  function removeLine(index:number) {
    setLines(old=>old.filter((_,i)=>i!==index));
  }
  async function reloadRecipe() {
    if(!productId)return;
    setRecipe(await getProductRecipe(productId,variantId||undefined));
  }
  async function saveDraft() {
    if(!productId||!recipeName.trim()||lines.length===0)return;
    if(lines.some(line=>!line.stock_item_id||!line.unit_code||!line.quantity.trim())) {
      setError("برای همه ردیف‌ها ماده، واحد و مقدار معتبر انتخاب کنید.");return;
    }
    setSaving(true);setError(null);setNotice(null);
    try {
      await createRecipeDraftVersion(productId,{
        name:recipeName.trim(),
        ...(variantId?{variant_id:variantId}:{}),
        lines,
      });
      await reloadRecipe();
      setNotice("نسخه پیش‌نویس جدید رسپی ذخیره شد؛ برای استفاده باید جداگانه فعال شود.");
    } catch(e) { setError(e instanceof ApiError?e.message:"ساخت نسخه رسپی انجام نشد."); }
    finally { setSaving(false); }
  }
  async function activate(versionId:string,versionNo:number) {
    if(!window.confirm(`نسخه ${versionNo} فعال شود؟ نسخه فعال فعلی بازنشسته خواهد شد.`))return;
    setSaving(true);setError(null);setNotice(null);
    try {
      await activateRecipeVersion(versionId);
      await reloadRecipe();
      setNotice(`نسخه ${versionNo} فعال شد.`);
    } catch(e) { setError(e instanceof ApiError?e.message:"فعال‌سازی نسخه انجام نشد."); }
    finally { setSaving(false); }
  }

  const variants=product?.variants.filter(v=>v.is_active)??[];
  return <div className="page-stack">
    <PageHeader eyebrow="CATALOG & INVENTORY" title="رسپی و ترکیبات" description="تعریف مواد مصرفی هر محصول با نسخه‌بندی مستقل و قابل ممیزی" action={<Button variant="secondary" onClick={()=>void loadBase()} disabled={loading}><RefreshCw size={16}/> تازه‌سازی</Button>}/>
    <Notice variant="info">در این بخش فقط رسپی و نسخه مواد تعریف می‌شود. ثبت مصرف واقعی موجودی به سیاست رزرو و زمان مصرف سفارش وابسته است و تا تصویب آن، از این صفحه انجام نمی‌شود.</Notice>
    {error?<div className="recipe-error" role="alert"><AlertCircle size={16}/>{error}</div>:null}
    {notice?<div className="recipe-success" role="status"><CheckCircle2 size={16}/>{notice}</div>:null}
    {loading?<Surface padding="md">در حال دریافت کاتالوگ و مواد اولیه…</Surface>:products.length===0?<Surface padding="md">برای مدیریت رسپی ابتدا محصول فعال ایجاد کنید.</Surface>:<>
      <Surface padding="md" className="recipe-panel">
        <div className="recipe-grid">
          <label>محصول<select value={productId} onChange={e=>{setProductId(e.target.value);setVariantId("");}}>{products.map(p=><option key={p.id} value={p.id}>{p.name}</option>)}</select></label>
          <label>تنوع محصول<select value={variantId} onChange={e=>setVariantId(e.target.value)}><option value="">رسپی عمومی محصول</option>{variants.map(v=><option key={v.id} value={v.id}>{v.name}</option>)}</select></label>
        </div>
        {recipe?<div className="recipe-history">
          <div className="recipe-section-heading"><div><h2>{recipe.name}</h2><p>نسخه‌های ثبت‌شده برای این محصول و تنوع</p></div><span>{recipe.versions.length.toLocaleString("fa-IR")} نسخه</span></div>
          {recipe.versions.map(version=><article className="recipe-version" key={version.id}>
            <div className="recipe-version-head"><strong>نسخه {version.version_no.toLocaleString("fa-IR")}</strong><span className={`recipe-status recipe-status--${version.status}`}>{statusLabels[version.status]??version.status}</span>{version.effective_from?<small>{new Intl.DateTimeFormat("fa-IR",{dateStyle:"short",timeStyle:"short"}).format(new Date(version.effective_from.replace(" ","T")+"Z"))}</small>:null}</div>
            <div className="recipe-version-lines">{version.lines.map((line,i)=><span key={line.stock_item_id+line.unit_code}>{i+1}. {line.stock_item_name} — {format(line.quantity)} {line.unit_name} · ضایعات {format(line.waste_percent)}٪ · تبدیل {format(line.conversion_factor_snapshot)}</span>)}</div>
            {version.status==="draft"?<Button size="sm" onClick={()=>void activate(version.id,version.version_no)} disabled={saving}>فعال‌سازی نسخه</Button>:null}
          </article>)}
        </div>:<p className="recipe-empty">برای این محصول/تنوع هنوز رسپی ثبت نشده است.</p>}
      </Surface>
      <Surface padding="md" className="recipe-panel">
        <div className="recipe-section-heading"><div><h2>ساخت نسخه پیش‌نویس</h2><p>نسخه فعال تغییر نمی‌کند تا نسخه جدید را صریحاً فعال کنید.</p></div><ChefHat size={20}/></div>
        <label className="recipe-name">نام رسپی<input value={recipeName} onChange={e=>setRecipeName(e.target.value)} maxLength={160}/></label>
        <div className="recipe-lines">
          {lines.map((line,index)=>{
            const item=stockItems.find(x=>x.id===line.stock_item_id);
            const compatible=units.filter(u=>!item||u.dimension===item.base_unit.dimension);
            return <div className="recipe-line" key={index}>
              <label>ماده اولیه<select value={line.stock_item_id} onChange={e=>{const next=stockItems.find(x=>x.id===e.target.value);updateLine(index,{stock_item_id:e.target.value,unit_code:next?.base_unit.code??""});}}><option value="">انتخاب ماده</option>{stockItems.map(x=><option key={x.id} value={x.id}>{x.name} ({x.base_unit.name})</option>)}</select></label>
              <label>مقدار<input inputMode="decimal" value={line.quantity} onChange={e=>updateLine(index,{quantity:normalizeDecimalInput(e.target.value)})}/></label>
              <label>واحد<select value={line.unit_code} onChange={e=>updateLine(index,{unit_code:e.target.value})}><option value="">انتخاب واحد</option>{compatible.map(u=><option key={u.code} value={u.code}>{u.name}</option>)}</select></label>
              <label>ضایعات ٪<input inputMode="decimal" value={line.waste_percent} onChange={e=>updateLine(index,{waste_percent:normalizeDecimalInput(e.target.value)})}/></label>
              {lines.length>1?<Button size="sm" variant="ghost" onClick={()=>removeLine(index)}>حذف</Button>:null}
            </div>;
          })}
        </div>
        <div className="recipe-actions"><Button size="sm" variant="secondary" onClick={addLine} disabled={lines.length>=100}><Plus size={15}/> افزودن ماده</Button><Button onClick={()=>void saveDraft()} disabled={saving||!stockItems.length}><Save size={16}/>{saving?"در حال ذخیره…":"ذخیره پیش‌نویس"}</Button></div>
      </Surface>
    </>}
  </div>;
}
