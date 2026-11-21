import { useEffect, useMemo, useState } from "react";
import type { FormEvent } from "react";
import { Activity, Boxes, ClipboardList, MapPin, Package, RefreshCw, Search } from "lucide-react";
import {
  ApiError,
  getInventoryBalances,
  getInventoryLedger,
  getInventoryLocations,
  getInventoryStockItems,
  createInventoryStockItem,
  updateInventoryStockItem,
  createInventoryLocation,
  updateInventoryLocation,
  getInventoryUnits,
  getInventoryDocuments,
  getInventoryDocument,
  getInventorySuppliers,
  createInventorySupplier,
  updateInventorySupplier,
  createInventoryDocument,
  addInventoryDocumentLine,
  updateInventoryDocumentLine,
  deleteInventoryDocumentLine,
  postInventoryDocument,
  type InventoryBalance,
  type InventoryLedgerEntry,
  type InventoryLocation,
  type InventoryStockItem,
  type InventoryUnit,
  type InventoryDocumentSummary,
  type InventoryDocumentDetail,
  type InventoryDocumentType,
  type InventorySupplier,
} from "../../shared/api/client";
import { Button, Notice, Surface } from "../../shared/ui";
import "./inventory.css";

const numberFormat = new Intl.NumberFormat("fa-IR", { maximumFractionDigits: 3 });
const quantity = (value: string | number) => numberFormat.format(Number(value) || 0);
const dateTime = (value: string) => {
  const date = new Date(value.includes("Z") || /[+-]\d\d:\d\d$/.test(value) ? value : `${value.replace(" ", "T")}Z`);
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat("fa-IR", { dateStyle: "short", timeStyle: "short" }).format(date);
};
const documentLabel: Record<string, string> = {
  purchase_receipt: "رسید خرید",
  consumption: "مصرف مواد",
  transfer: "انتقال بین انبارک‌ها",
  adjustment_in: "تعدیل افزایشی",
  adjustment_out: "تعدیل کاهشی",
  waste: "ثبت ضایعات",
  return: "برگشت",
};
const documentStatus: Record<string, string> = { draft: "پیش‌نویس", posted: "ثبت نهایی", voided: "باطل‌شده" };
const movementLabel: Record<string, string> = {
  purchase_receipt: "رسید خرید",
  adjustment_in: "تعدیل افزایشی",
  adjustment_out: "تعدیل کاهشی",
  consumption: "مصرف",
  waste: "ضایعات",
  transfer_in: "انتقال ورودی",
  transfer_out: "انتقال خروجی",
  return: "برگشت",
};

export function InventoryWorkspace() {
  const [items, setItems] = useState<InventoryStockItem[]>([]);
  const [locations, setLocations] = useState<InventoryLocation[]>([]);
  const [balances, setBalances] = useState<InventoryBalance[]>([]);
  const [ledger, setLedger] = useState<InventoryLedgerEntry[]>([]);
  const [ledgerCursor, setLedgerCursor] = useState<string | null>(null);
  const [ledgerLoadingMore, setLedgerLoadingMore] = useState(false);
  const [ledgerError, setLedgerError] = useState<string | null>(null);
  const [units, setUnits] = useState<InventoryUnit[]>([]);
  const [documents, setDocuments] = useState<InventoryDocumentSummary[]>([]);
  const [suppliers, setSuppliers] = useState<InventorySupplier[]>([]);
  const [documentSupplier, setDocumentSupplier] = useState("");
  const [supplierEditorId, setSupplierEditorId] = useState<string | null>(null);
  const [supplierName, setSupplierName] = useState("");
  const [supplierPhone, setSupplierPhone] = useState("");
  const [supplierNotes, setSupplierNotes] = useState("");
  const [supplierActive, setSupplierActive] = useState(true);
  const [activeDocument, setActiveDocument] = useState<InventoryDocumentDetail | null>(null);
  const [documentDetailBusy, setDocumentDetailBusy] = useState(false);
  const [editingLineId, setEditingLineId] = useState<string | null>(null);
  const [editingItemId, setEditingItemId] = useState("");
  const [editingQuantity, setEditingQuantity] = useState("");
  const [editingUnit, setEditingUnit] = useState("");
  const [editingCost, setEditingCost] = useState("");
  const [documentType, setDocumentType] = useState<InventoryDocumentType>("consumption");
  const [documentLocation, setDocumentLocation] = useState("");
  const [destinationLocation, setDestinationLocation] = useState("");
  const [documentItem, setDocumentItem] = useState("");
  const [documentUnit, setDocumentUnit] = useState("");
  const [documentQuantity, setDocumentQuantity] = useState("1");
  const [documentCost, setDocumentCost] = useState("");
  const [documentReason, setDocumentReason] = useState("");
  const [documentBusy, setDocumentBusy] = useState(false);
  const [documentMessage, setDocumentMessage] = useState<string | null>(null);
  const [query, setQuery] = useState("");
  const [locationId, setLocationId] = useState("all");
  const [selectedId, setSelectedId] = useState("");
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [refreshing, setRefreshing] = useState(false);
  const [itemEditorId, setItemEditorId] = useState<string | null>(null);
  const [itemCode, setItemCode] = useState("");
  const [itemName, setItemName] = useState("");
  const [itemUnit, setItemUnit] = useState("");
  const [itemActive, setItemActive] = useState(true);
  const [locationEditorId, setLocationEditorId] = useState<string | null>(null);
  const [locationCode, setLocationCode] = useState("");
  const [locationName, setLocationName] = useState("");
  const [locationType, setLocationType] = useState<InventoryLocation["location_type"]>("storage");
  const [locationActive, setLocationActive] = useState(true);
  const [managementBusy, setManagementBusy] = useState(false);
  const [managementMessage, setManagementMessage] = useState<string | null>(null);

  async function loadData() {
    try {
      const [stockItems, stockLocations, stockBalances, ledgerPage, stockUnits, stockDocuments, stockSuppliers] = await Promise.all([
        getInventoryStockItems("", true),
        getInventoryLocations(true),
        getInventoryBalances({ limit: 200 }),
        getInventoryLedger({ limit: 50 }),
        getInventoryUnits(),
        getInventoryDocuments(50),
        getInventorySuppliers(true),
      ]);
      setItems(stockItems);
      setLocations(stockLocations);
      setBalances(stockBalances);
      setLedger(ledgerPage.items);
      setLedgerCursor(ledgerPage.nextCursor);
      setUnits(stockUnits);
      setDocuments(stockDocuments);
      setSuppliers(stockSuppliers);
      setDocumentSupplier((current) => stockSuppliers.some((supplier) => supplier.id === current && supplier.is_active) ? current : stockSuppliers.find((supplier) => supplier.is_active)?.id ?? "");
      const activeItems = stockItems.filter((item) => item.is_active);
      const activeLocations = stockLocations.filter((location) => location.is_active);
      setSelectedId((current) => stockItems.some((item) => item.id === current) ? current : activeItems[0]?.id ?? "");
      setDocumentLocation((current) => activeLocations.some((location) => location.id === current) ? current : activeLocations[0]?.id ?? "");
      setDestinationLocation((current) => activeLocations.find((location) => location.id !== current)?.id ?? "");
      setDocumentItem((current) => activeItems.some((item) => item.id === current) ? current : activeItems[0]?.id ?? "");
      setDocumentUnit((current) => current || activeItems[0]?.base_unit.code || stockUnits[0]?.code || "");
    } catch (cause) {
      setError(cause instanceof ApiError ? cause.message : "دریافت اطلاعات واقعی انبار انجام نشد.");
    } finally {
      setLoading(false);
      setRefreshing(false);
    }
  }

  async function refreshData() {
    setRefreshing(true);
    setError(null);
    await loadData();
  }

  async function loadMoreLedger() {
    if (!ledgerCursor || ledgerLoadingMore) return;
    setLedgerLoadingMore(true);
    setLedgerError(null);
    try {
      const page = await getInventoryLedger({ limit: 50, beforeId: ledgerCursor });
      setLedger((current) => [...current, ...page.items]);
      setLedgerCursor(page.nextCursor);
    } catch (cause) {
      setLedgerError(cause instanceof ApiError ? cause.message : "دریافت گردش‌های قدیمی‌تر انجام نشد.");
    } finally {
      setLedgerLoadingMore(false);
    }
  }

  // This effect starts an asynchronous API request; state updates happen after the request settles.
  // eslint-disable-next-line react-hooks/set-state-in-effect
  useEffect(() => { void loadData(); }, []);

  const filteredItems = useMemo(() => {
    const normalized = query.trim().toLocaleLowerCase("fa-IR");
    return items.filter((item) => {
      const matchesQuery = !normalized || `${item.name} ${item.code}`.toLocaleLowerCase("fa-IR").includes(normalized);
      const matchesLocation = locationId === "all" || balances.some((balance) => balance.stock_item_id === item.id && balance.location_id === locationId);
      return matchesQuery && matchesLocation;
    });
  }, [items, query, locationId, balances]);

  const activeItems = items.filter((item) => item.is_active);
  const activeLocations = locations.filter((location) => location.is_active);
  const selected = items.find((item) => item.id === selectedId) ?? null;
  const selectedBalances = balances.filter((balance) => balance.stock_item_id === selectedId && (locationId === "all" || balance.location_id === locationId));
  const selectedLedger = ledger.filter((entry) => entry.stock_item_id === selectedId && (locationId === "all" || entry.location_id === locationId));
  function beginEditLine(line: InventoryDocumentDetail["lines"][number]) {
    setEditingLineId(line.id);
    setEditingItemId(line.stock_item_id);
    setEditingQuantity(line.quantity);
    setEditingUnit(line.unit_code);
    setEditingCost("");
  }

  async function saveDocumentLine() {
    if (!activeDocument || !editingLineId || !editingItemId || !editingUnit || !editingQuantity.trim()) return;
    if (["purchase_receipt", "adjustment_in"].includes(activeDocument.document_type) && !editingCost.trim()) {
      setDocumentMessage("مبلغ کل این ردیف به تومان الزامی است.");
      return;
    }
    setDocumentDetailBusy(true);
    setDocumentMessage(null);
    try {
      await updateInventoryDocumentLine(activeDocument.id, editingLineId, {
        stock_item_id: editingItemId, quantity: editingQuantity, unit_code: editingUnit,
        ...(["purchase_receipt", "adjustment_in"].includes(activeDocument.document_type) ? { total_cost_amount: editingCost } : {}),
      });
      setActiveDocument(await getInventoryDocument(activeDocument.id));
      setEditingLineId(null);
      await refreshData();
    } catch (cause) {
      setDocumentMessage(cause instanceof ApiError ? cause.message : "ویرایش ردیف سند انجام نشد.");
    } finally {
      setDocumentDetailBusy(false);
    }
  }

  async function removeDocumentLine(lineId: string) {
    if (!activeDocument || activeDocument.status !== "draft") return;
    setDocumentDetailBusy(true);
    setDocumentMessage(null);
    try {
      await deleteInventoryDocumentLine(activeDocument.id, lineId);
      setActiveDocument(await getInventoryDocument(activeDocument.id));
      await refreshData();
    } catch (cause) {
      setDocumentMessage(cause instanceof ApiError ? cause.message : "حذف ردیف سند انجام نشد.");
    } finally {
      setDocumentDetailBusy(false);
    }
  }

  async function openDocument(documentId: string) {
    setDocumentDetailBusy(true);
    setDocumentMessage(null);
    try {
      setActiveDocument(await getInventoryDocument(documentId));
    } catch (cause) {
      setDocumentMessage(cause instanceof ApiError ? cause.message : "دریافت جزئیات سند انجام نشد.");
    } finally {
      setDocumentDetailBusy(false);
    }
  }

  const needsReason = ["adjustment_in", "adjustment_out", "waste"].includes(documentType);
  const needsCost = ["adjustment_in", "purchase_receipt"].includes(documentType);
  const selectedDocumentItem = activeItems.find((item) => item.id === documentItem) ?? null;

  function editStockItem(item: InventoryStockItem) {
    setItemEditorId(item.id);
    setItemCode(item.code);
    setItemName(item.name);
    setItemUnit(item.base_unit.code);
    setItemActive(item.is_active);
    setManagementMessage(null);
  }

  async function saveStockItem(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!itemCode.trim() || !itemName.trim() || (!itemEditorId && !itemUnit)) return;
    setManagementBusy(true);
    setManagementMessage(null);
    try {
      if (itemEditorId) {
        await updateInventoryStockItem(itemEditorId, { code: itemCode.trim(), name: itemName.trim(), is_active: itemActive });
        setManagementMessage("مشخصات قلم انبار به‌روزرسانی شد.");
      } else {
        await createInventoryStockItem({ code: itemCode.trim(), name: itemName.trim(), base_unit_code: itemUnit });
        setManagementMessage("قلم جدید به فهرست انبار اضافه شد.");
      }
      setItemEditorId(null); setItemCode(""); setItemName(""); setItemUnit(""); setItemActive(true);
      await refreshData();
    } catch (cause) {
      setManagementMessage(cause instanceof ApiError ? cause.message : "ذخیره قلم انبار انجام نشد.");
    } finally { setManagementBusy(false); }
  }

  function editLocation(location: InventoryLocation) {
    setLocationEditorId(location.id);
    setLocationCode(location.code);
    setLocationName(location.name);
    setLocationType(location.location_type);
    setLocationActive(location.is_active);
    setManagementMessage(null);
  }

  async function saveLocation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!locationCode.trim() || !locationName.trim()) return;
    setManagementBusy(true);
    setManagementMessage(null);
    try {
      const payload = {
        code: locationCode.trim(), name: locationName.trim(),
        location_type: locationType as "storage" | "kitchen" | "bar" | "freezer" | "other",
        is_active: locationEditorId ? locationActive : true,
      };
      if (locationEditorId) {
        await updateInventoryLocation(locationEditorId, payload);
        setManagementMessage("مشخصات انبارک به‌روزرسانی شد.");
      } else {
        await createInventoryLocation({ code: payload.code, name: payload.name, location_type: payload.location_type });
        setManagementMessage("انبارک جدید ایجاد شد.");
      }
      setLocationEditorId(null); setLocationCode(""); setLocationName(""); setLocationType("storage"); setLocationActive(true);
      await refreshData();
    } catch (cause) {
      setManagementMessage(cause instanceof ApiError ? cause.message : "ذخیره انبارک انجام نشد.");
    } finally { setManagementBusy(false); }
  }

  async function saveSupplier(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!supplierName.trim()) return;
    setManagementBusy(true);
    setManagementMessage(null);
    try {
      if (supplierEditorId) {
        await updateInventorySupplier(supplierEditorId, {
          name: supplierName.trim(), phone: supplierPhone.trim() || null,
          notes: supplierNotes.trim() || null, is_active: supplierActive,
        });
        setManagementMessage("مشخصات تأمین‌کننده به‌روزرسانی شد.");
      } else {
        await createInventorySupplier({
          name: supplierName.trim(),
          ...(supplierPhone.trim() ? { phone: supplierPhone.trim() } : {}),
          ...(supplierNotes.trim() ? { notes: supplierNotes.trim() } : {}),
        });
        setManagementMessage("تأمین‌کننده جدید ثبت شد.");
      }
      setSupplierEditorId(null); setSupplierName(""); setSupplierPhone(""); setSupplierNotes(""); setSupplierActive(true);
      await refreshData();
    } catch (cause) {
      setManagementMessage(cause instanceof ApiError ? cause.message : "ذخیره تأمین‌کننده انجام نشد.");
    } finally { setManagementBusy(false); }
  }

  function editSupplier(supplier: InventorySupplier) {
    setSupplierEditorId(supplier.id); setSupplierName(supplier.name);
    setSupplierPhone(supplier.phone ?? ""); setSupplierNotes(supplier.notes ?? "");
    setSupplierActive(supplier.is_active); setManagementMessage(null);
  }

  async function createDraftOnly() {
    if (!documentLocation) return;
    if (documentType === "purchase_receipt" && !documentSupplier) { setDocumentMessage("برای رسید خرید، ابتدا تأمین‌کننده فعال انتخاب کنید."); return; }
    if (needsReason && !documentReason.trim()) {
      setDocumentMessage("برای تعدیل و ضایعات، ثبت دلیل الزامی است.");
      return;
    }
    setDocumentBusy(true);
    setDocumentMessage(null);
    try {
      const draft = await createInventoryDocument({
        document_type: documentType,
        location_id: documentLocation,
        ...(documentType === "purchase_receipt" ? { supplier_id: documentSupplier } : {}),
        ...(documentType === "transfer" ? { destination_location_id: destinationLocation } : {}),
        ...(documentReason.trim() ? { reason: documentReason.trim() } : {}),
      });
      setDocumentMessage(`پیش‌نویس سند ${draft.document_no} ایجاد شد. اکنون می‌توانید چند ردیف به آن اضافه کنید.`);
      await refreshData();
      await openDocument(draft.id);
    } catch (cause) {
      setDocumentMessage(cause instanceof ApiError ? cause.message : "ایجاد پیش‌نویس انجام نشد.");
    } finally {
      setDocumentBusy(false);
    }
  }

  async function addLineToActiveDraft() {
    if (!activeDocument || activeDocument.status !== "draft" || !documentItem || !documentUnit || !documentQuantity.trim()) return;
    if (["adjustment_in", "purchase_receipt"].includes(activeDocument.document_type) && !documentCost.trim()) {
      setDocumentMessage("برای این سند، مبلغ کل خرید/ورودی به تومان الزامی است.");
      return;
    }
    setDocumentDetailBusy(true);
    setDocumentMessage(null);
    try {
      await addInventoryDocumentLine(activeDocument.id, {
        stock_item_id: documentItem,
        quantity: documentQuantity,
        unit_code: documentUnit,
        ...(["adjustment_in", "purchase_receipt"].includes(activeDocument.document_type) ? { total_cost_amount: documentCost } : {}),
      });
      setActiveDocument(await getInventoryDocument(activeDocument.id));
      setDocumentQuantity("1");
      setDocumentCost("");
      await refreshData();
    } catch (cause) {
      setDocumentMessage(cause instanceof ApiError ? cause.message : "افزودن ردیف به پیش‌نویس انجام نشد.");
    } finally {
      setDocumentDetailBusy(false);
    }
  }

  async function finalizeActiveDraft() {
    if (!activeDocument || activeDocument.status !== "draft" || !activeDocument.lines.length) return;
    setDocumentDetailBusy(true);
    setDocumentMessage(null);
    try {
      await postInventoryDocument(activeDocument.id);
      setActiveDocument(await getInventoryDocument(activeDocument.id));
      setDocumentMessage(`سند ${activeDocument.document_no} با موفقیت ثبت نهایی شد.`);
      await refreshData();
    } catch (cause) {
      setDocumentMessage(cause instanceof ApiError ? cause.message : "ثبت نهایی سند انجام نشد.");
    } finally {
      setDocumentDetailBusy(false);
    }
  }

  async function submitStockDocument(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!documentLocation || !documentItem || !documentUnit || !documentQuantity.trim()) return;
    if (documentType === "purchase_receipt" && !documentSupplier) { setDocumentMessage("برای رسید خرید، انتخاب تأمین‌کننده الزامی است."); return; }
    if (documentType === "transfer" && (!destinationLocation || destinationLocation === documentLocation)) {
      setDocumentMessage("انبارک مقصد باید با انبارک مبدأ متفاوت باشد.");
      return;
    }
    if (needsReason && !documentReason.trim()) {
      setDocumentMessage("برای تعدیل و ضایعات، ثبت دلیل الزامی است.");
      return;
    }
    if (needsCost && !documentCost.trim()) {
      setDocumentMessage("برای تعدیل افزایشی، مبلغ کل خرید/ورودی به تومان الزامی است.");
      return;
    }
    setDocumentBusy(true);
    setDocumentMessage(null);
    let createdDocument: { id: string; document_no: string } | null = null;
    try {
      createdDocument = await createInventoryDocument({
        document_type: documentType,
        location_id: documentLocation,
        ...(documentType === "purchase_receipt" ? { supplier_id: documentSupplier } : {}),
        ...(documentType === "transfer" ? { destination_location_id: destinationLocation } : {}),
        ...(documentReason.trim() ? { reason: documentReason.trim() } : {}),
      });
      await addInventoryDocumentLine(createdDocument.id, {
        stock_item_id: documentItem,
        quantity: documentQuantity,
        unit_code: documentUnit,
        ...(needsCost ? { total_cost_amount: documentCost } : {}),
      });
      await postInventoryDocument(createdDocument.id);
      setDocumentMessage(`سند ${createdDocument.document_no} با موفقیت ثبت نهایی شد.`);
      setDocumentQuantity("1");
      setDocumentCost("");
      setDocumentReason("");
      await refreshData();
    } catch (cause) {
      const detail = cause instanceof ApiError ? cause.message : "ثبت سند انجام نشد.";
      if (createdDocument) {
        await refreshData();
        try {
          const recovered = await getInventoryDocument(createdDocument.id);
          setActiveDocument(recovered);
          setDocumentMessage(recovered.status === "posted"
            ? `وضعیت سند ${createdDocument.document_no} در سرور «ثبت نهایی» است؛ مانده انبار را به‌روزرسانی کردیم.`
            : `سند ${createdDocument.document_no} به‌صورت پیش‌نویس باقی مانده است. جزئیات آن باز شد تا بدون ایجاد سند تکراری ادامه دهید. خطا: ${detail}`);
        } catch {
          setDocumentMessage(`سند ${createdDocument.document_no} ایجاد شده است اما وضعیت نهایی آن قابل دریافت نیست. سند جدید نسازید؛ از فهرست اسناد، همین شماره را بررسی کنید. خطا: ${detail}`);
        }
      } else {
        setDocumentMessage(detail);
      }
    } finally {
      setDocumentBusy(false);
    }
  }

  const totalsByUnit = useMemo(() => {
    const grouped = new Map<string, { unitCode: string; unitName: string; onHand: number; available: number }>();
    for (const balance of balances) {
      const unitCode = balance.base_unit_code;
      const unit = units.find((entry) => entry.code === unitCode);
      const current = grouped.get(unitCode) ?? {
        unitCode,
        unitName: unit?.name ?? unitCode,
        onHand: 0,
        available: 0,
      };
      current.onHand += Number(balance.quantity_on_hand);
      current.available += Number(balance.quantity_available);
      grouped.set(unitCode, current);
    }
    return Array.from(grouped.values()).sort((left, right) => left.unitCode.localeCompare(right.unitCode));
  }, [balances, units]);

  return (
    <div className="page-stack inventory-workspace">
      <div className="inventory-heading">
        <div>
          <span className="ui-eyebrow">INVENTORY CONTROL</span>
          <h1>مواد اولیه و انبار</h1>
          <p>مانده موجودی و گردش ثبت‌شده در کسب‌وکار و شعبه فعال</p>
        </div>
        <Button variant="secondary" onClick={() => void refreshData()} disabled={loading || refreshing}>
          <RefreshCw size={15} /> {refreshing ? "در حال به‌روزرسانی..." : "به‌روزرسانی"}
        </Button>
      </div>

      <Notice variant="info">اطلاعات این صفحه مستقیماً از API انبار و دیتابیس دریافت می‌شود. ثبت یا تغییر موجودی فقط از طریق اسناد رسمی انبار انجام می‌شود.</Notice>
      {error ? <Notice variant="warning">{error}</Notice> : null}

      <div className="inventory-metrics">
        <Surface padding="sm"><span>اقلام فعال</span><strong>{loading ? "—" : quantity(activeItems.length)}</strong></Surface>
        <Surface padding="sm">
          <span>موجودی فیزیکی · به تفکیک واحد</span>
          <div className="inventory-metric-breakdown">
            {loading ? <span>—</span> : totalsByUnit.map((total) => <span key={total.unitCode}><b>{quantity(total.onHand)}</b> {total.unitName}</span>)}
            {!loading && totalsByUnit.length === 0 ? <span>بدون موجودی ثبت‌شده</span> : null}
          </div>
        </Surface>
        <Surface padding="sm">
          <span>موجودی قابل استفاده · به تفکیک واحد</span>
          <div className="inventory-metric-breakdown">
            {loading ? <span>—</span> : totalsByUnit.map((total) => <span key={total.unitCode}><b>{quantity(total.available)}</b> {total.unitName}</span>)}
            {!loading && totalsByUnit.length === 0 ? <span>بدون موجودی ثبت‌شده</span> : null}
          </div>
        </Surface>
      </div>

      <Surface padding="md" className="inventory-document-composer">
        <div className="inventory-panel-heading">
          <div><h2><ClipboardList size={17} /> ثبت گردش انبار</h2><p>ثبت سند و اعمال موجودی از مسیر رسمی انبار</p></div>
        </div>
        <form className="inventory-document-form" onSubmit={submitStockDocument}>
          <div className="inventory-document-fields">
            <label>نوع سند<select value={documentType} onChange={(event) => setDocumentType(event.target.value as InventoryDocumentType)}>
              <option value="consumption">مصرف مواد</option><option value="purchase_receipt">رسید خرید</option><option value="waste">ضایعات</option><option value="adjustment_in">تعدیل افزایشی</option><option value="adjustment_out">تعدیل کاهشی</option><option value="transfer">انتقال بین انبارک‌ها</option>
            </select></label>
            {documentType === "purchase_receipt" ? <label>تأمین‌کننده<select required value={documentSupplier} onChange={(event) => setDocumentSupplier(event.target.value)}><option value="">انتخاب تأمین‌کننده</option>{suppliers.filter((supplier) => supplier.is_active).map((supplier) => <option key={supplier.id} value={supplier.id}>{supplier.name}{supplier.phone ? ` · ${supplier.phone}` : ""}</option>)}</select></label> : null}
            <label>انبارک مبدأ<select required value={documentLocation} onChange={(event) => setDocumentLocation(event.target.value)}>{activeLocations.map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label>
            {documentType === "transfer" ? <label>انبارک مقصد<select required value={destinationLocation} onChange={(event) => setDestinationLocation(event.target.value)}>{activeLocations.filter((location) => location.id !== documentLocation).map((location) => <option key={location.id} value={location.id}>{location.name}</option>)}</select></label> : null}
            <label>قلم انبار<select required value={documentItem} onChange={(event) => { const item = items.find((entry) => entry.id === event.target.value); setDocumentItem(event.target.value); if (item) setDocumentUnit(item.base_unit.code); }}>{activeItems.map((item) => <option key={item.id} value={item.id}>{item.name} ({item.code})</option>)}</select></label>
            <label>واحد<select required value={documentUnit} onChange={(event) => setDocumentUnit(event.target.value)}>{units.filter((unit) => !selectedDocumentItem || unit.dimension === selectedDocumentItem.base_unit.dimension).map((unit) => <option key={unit.code} value={unit.code}>{unit.name} ({unit.code})</option>)}</select></label>
            <label>مقدار<input required inputMode="decimal" pattern="^[0-9]+([.][0-9]{1,6})?$" value={documentQuantity} onChange={(event) => setDocumentQuantity(event.target.value)} /></label>
            {needsCost ? <label>مبلغ کل خرید/ورودی (تومان)<input required inputMode="numeric" pattern="^[0-9]+$" value={documentCost} onChange={(event) => setDocumentCost(event.target.value)} /></label> : null}
            {needsReason ? <label className="inventory-document-reason">دلیل ثبت<input required maxLength={500} value={documentReason} onChange={(event) => setDocumentReason(event.target.value)} placeholder="شرح دلیل تعدیل یا ضایعات" /></label> : null}
          </div>
          {documentMessage ? <p className="inventory-document-message" role="status">{documentMessage}</p> : null}
          <div className="inventory-document-actions"><span>می‌توانید پیش‌نویس چندردیفی بسازید یا یک سند تک‌ردیفی را مستقیم ثبت نهایی کنید.</span><div><Button variant="secondary" type="button" disabled={documentBusy || loading || !activeLocations.length} onClick={() => void createDraftOnly()}>ایجاد پیش‌نویس</Button><Button type="submit" disabled={documentBusy || loading || !activeLocations.length || !activeItems.length || !units.length}>{documentBusy ? "در حال ثبت..." : "ثبت سریع سند"}</Button></div></div>
        </form>
      </Surface>

      <Surface padding="md" className="inventory-documents-panel">
        <div className="inventory-panel-heading"><div><h2><ClipboardList size={17} /> اسناد اخیر انبار</h2><p>آخرین اسناد همین شعبه</p></div></div>
        <div className="inventory-documents-list">
          {documents.map((document) => <div className="inventory-document-row" key={document.id}>
            <div><strong>{documentLabel[document.document_type] ?? document.document_type} · {document.document_no}</strong><small>{document.location.name}{document.destination ? ` ← ${document.destination.name}` : ""}{document.supplier ? ` · تأمین‌کننده: ${document.supplier.name}` : ""} · {dateTime(document.created_at)}</small>{document.reason ? <small>{document.reason}</small> : null}</div>
            <div className="inventory-document-row-actions"><span className={`inventory-document-status is-${document.status}`}>{documentStatus[document.status] ?? document.status}</span><button type="button" onClick={() => void openDocument(document.id)} disabled={documentDetailBusy}>جزئیات</button></div>
          </div>)}
          {!documents.length ? <div className="inventory-empty">هنوز سندی برای این شعبه ثبت نشده است.</div> : null}
          {activeDocument ? <div className="inventory-document-detail">
            <div className="inventory-document-detail-heading"><strong>سند {activeDocument.document_no}</strong>{activeDocument.supplier ? <small>تأمین‌کننده: {activeDocument.supplier.name}</small> : null}<div>{activeDocument.status === "draft" ? <><button type="button" disabled={documentDetailBusy || !documentItem} onClick={() => void addLineToActiveDraft()}>افزودن قلم انتخاب‌شده</button><button type="button" disabled={documentDetailBusy || !activeDocument.lines.length} onClick={() => void finalizeActiveDraft()}>ثبت نهایی</button></> : null}<button type="button" onClick={() => setActiveDocument(null)}>بستن</button></div></div>
            <div className="inventory-document-lines">
              {activeDocument.lines.map((line) => <div className="inventory-document-line-detail" key={line.id}>
                {editingLineId === line.id ? <>
                  <label><span>قلم</span><select value={editingItemId} onChange={(event) => { const item = items.find((entry) => entry.id === event.target.value); setEditingItemId(event.target.value); if (item) setEditingUnit(item.base_unit.code); }}>{activeItems.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></label>
                  <label><span>مقدار</span><input value={editingQuantity} onChange={(event) => setEditingQuantity(event.target.value)} inputMode="decimal" /></label>
                  <label><span>واحد</span><select value={editingUnit} onChange={(event) => setEditingUnit(event.target.value)}>{units.filter((unit) => { const item = items.find((entry) => entry.id === editingItemId); return !item || unit.dimension === item.base_unit.dimension; }).map((unit) => <option key={unit.code} value={unit.code}>{unit.name}</option>)}</select></label>
                  {["purchase_receipt", "adjustment_in"].includes(activeDocument.document_type) ? <label><span>مبلغ کل (تومان)</span><input required inputMode="numeric" pattern="^[0-9]+$" value={editingCost} onChange={(event) => setEditingCost(event.target.value)} /></label> : null}
                  <div className="inventory-line-actions"><button type="button" disabled={documentDetailBusy} onClick={() => void saveDocumentLine()}>ذخیره</button><button type="button" onClick={() => setEditingLineId(null)}>انصراف</button></div>
                </> : <>
                  <span>{line.stock_item_name}</span><b>{quantity(line.quantity)} {line.unit_name}</b><small>مقدار پایه: {quantity(line.base_quantity)}</small>
                  {activeDocument.status === "draft" ? <div className="inventory-line-actions"><button type="button" disabled={documentDetailBusy} onClick={() => beginEditLine(line)}>ویرایش</button><button type="button" disabled={documentDetailBusy} onClick={() => void removeDocumentLine(line.id)}>حذف</button></div> : null}
                </>}
              </div>)}
              {!activeDocument.lines.length ? <p>این سند هنوز ردیفی ندارد.</p> : null}
            </div>
          </div> : null}
        </div>
      </Surface>

      <Surface padding="md" className="inventory-management-panel">
        <div className="inventory-panel-heading"><div><h2>مدیریت اقلام، انبارک‌ها و تأمین‌کنندگان</h2><p>ایجاد، ویرایش و فعال/غیرفعال‌سازی با کنترل‌های سمت سرور</p></div></div>
        <div className="inventory-management-grid">
          <form className="inventory-management-form" onSubmit={saveStockItem}>
            <strong>{itemEditorId ? "ویرایش قلم انبار" : "افزودن قلم جدید"}</strong>
            <label>کد قلم<input required maxLength={64} pattern="[A-Za-z0-9._-]+" value={itemCode} onChange={(event) => setItemCode(event.target.value)} placeholder="مثلاً FLOUR-01" /></label>
            <label>نام قلم<input required maxLength={160} value={itemName} onChange={(event) => setItemName(event.target.value)} placeholder="مثلاً آرد سفید" /></label>
            {!itemEditorId ? <label>واحد پایه<select required value={itemUnit} onChange={(event) => setItemUnit(event.target.value)}><option value="">انتخاب واحد</option>{units.map((unit) => <option key={unit.code} value={unit.code}>{unit.name} ({unit.code})</option>)}</select></label> : <label>واحد پایه<input value={items.find((item) => item.id === itemEditorId)?.base_unit.name ?? ""} disabled /></label>}
            {itemEditorId ? <label className="inventory-management-check"><input type="checkbox" checked={itemActive} onChange={(event) => setItemActive(event.target.checked)} /> قلم فعال باشد</label> : null}
            <div className="inventory-management-actions"><button type="submit" disabled={managementBusy || !itemCode.trim() || !itemName.trim() || (!itemEditorId && !itemUnit)}>{managementBusy ? "در حال ذخیره..." : itemEditorId ? "ذخیره تغییرات" : "ایجاد قلم"}</button>{itemEditorId ? <button type="button" onClick={() => setItemEditorId(null)}>انصراف</button> : null}</div>
          </form>
          <form className="inventory-management-form" onSubmit={saveLocation}>
            <strong>{locationEditorId ? "ویرایش انبارک" : "افزودن انبارک جدید"}</strong>
            <label>کد انبارک<input required maxLength={40} pattern="[A-Za-z0-9._-]+" value={locationCode} onChange={(event) => setLocationCode(event.target.value)} placeholder="مثلاً MAIN" /></label>
            <label>نام انبارک<input required maxLength={120} value={locationName} onChange={(event) => setLocationName(event.target.value)} placeholder="مثلاً انبار اصلی" /></label>
            <label>نوع انبارک<select value={locationType} onChange={(event) => setLocationType(event.target.value as InventoryLocation["location_type"])}><option value="storage">انبار</option><option value="kitchen">آشپزخانه</option><option value="bar">بار</option><option value="freezer">فریزر</option><option value="other">سایر</option></select></label>
            {locationEditorId ? <label className="inventory-management-check"><input type="checkbox" checked={locationActive} onChange={(event) => setLocationActive(event.target.checked)} /> انبارک فعال باشد</label> : null}
            <div className="inventory-management-actions"><button type="submit" disabled={managementBusy || !locationCode.trim() || !locationName.trim()}>{managementBusy ? "در حال ذخیره..." : locationEditorId ? "ذخیره تغییرات" : "ایجاد انبارک"}</button>{locationEditorId ? <button type="button" onClick={() => setLocationEditorId(null)}>انصراف</button> : null}</div>
          </form>
          <form className="inventory-management-form" onSubmit={saveSupplier}>
            <strong>{supplierEditorId ? "ویرایش تأمین‌کننده" : "افزودن تأمین‌کننده"}</strong>
            <label>نام تأمین‌کننده<input required maxLength={180} value={supplierName} onChange={(event) => setSupplierName(event.target.value)} placeholder="نام شخص یا شرکت" /></label>
            <label>شماره تماس<input maxLength={32} value={supplierPhone} onChange={(event) => setSupplierPhone(event.target.value)} placeholder="شماره تماس (اختیاری)" /></label>
            <label>یادداشت<textarea maxLength={1000} rows={2} value={supplierNotes} onChange={(event) => setSupplierNotes(event.target.value)} placeholder="اطلاعات تکمیلی" /></label>
            {supplierEditorId ? <label className="inventory-management-check"><input type="checkbox" checked={supplierActive} onChange={(event) => setSupplierActive(event.target.checked)} /> تأمین‌کننده فعال باشد</label> : null}
            <div className="inventory-management-actions"><button type="submit" disabled={managementBusy || !supplierName.trim()}>{managementBusy ? "در حال ذخیره..." : supplierEditorId ? "ذخیره تغییرات" : "ثبت تأمین‌کننده"}</button>{supplierEditorId ? <button type="button" onClick={() => setSupplierEditorId(null)}>انصراف</button> : null}</div>
          </form>
        </div>
        {managementMessage ? <p className="inventory-document-message" role="status">{managementMessage}</p> : null}
        <div className="inventory-management-list">
          <div><strong>تأمین‌کنندگان</strong>{suppliers.map((supplier) => <div className="inventory-management-row" key={supplier.id}><span>{supplier.name}<small>{supplier.phone || "بدون شماره تماس"}</small></span><span className={supplier.is_active ? "is-enabled" : "is-disabled"}>{supplier.is_active ? "فعال" : "غیرفعال"}</span><button type="button" onClick={() => editSupplier(supplier)}>ویرایش</button></div>)}</div>
          <div><strong>اقلام ثبت‌شده</strong>{items.map((item) => <div className="inventory-management-row" key={item.id}><span>{item.name} <small>{item.code} · {item.base_unit.name}</small></span><span className={item.is_active ? "is-enabled" : "is-disabled"}>{item.is_active ? "فعال" : "غیرفعال"}</span><button type="button" onClick={() => editStockItem(item)}>ویرایش</button></div>)}</div>
          <div><strong>انبارک‌های این شعبه</strong>{locations.map((location) => <div className="inventory-management-row" key={location.id}><span>{location.name} <small>{location.code} · {location.location_type}</small></span><span className={location.is_active ? "is-enabled" : "is-disabled"}>{location.is_active ? "فعال" : "غیرفعال"}</span><button type="button" onClick={() => editLocation(location)}>ویرایش</button></div>)}</div>
        </div>
      </Surface>

      <div className="inventory-layout">
        <Surface padding="md" className="inventory-stock-panel">
          <div className="inventory-panel-heading">
            <div><h2><Boxes size={17} /> اقلام انبار</h2><p>{quantity(filteredItems.length)} قلم</p></div>
          </div>
          <label className="inventory-search"><Search size={16} /><input value={query} onChange={(event) => setQuery(event.target.value)} placeholder="جست‌وجوی نام یا کد کالا" /></label>
          <div className="inventory-locations">
            <button type="button" className={locationId === "all" ? "is-active" : ""} onClick={() => setLocationId("all")}>همه انبارک‌ها</button>
            {activeLocations.map((location) => <button type="button" key={location.id} className={locationId === location.id ? "is-active" : ""} onClick={() => setLocationId(location.id)}>{location.name}</button>)}
          </div>
          {loading ? <div className="inventory-empty">در حال دریافت موجودی از سرور...</div> : (
            <div className="inventory-stock-list">
              {filteredItems.map((item) => {
                const rows = balances.filter((balance) => balance.stock_item_id === item.id && (locationId === "all" || balance.location_id === locationId));
                const onHand = rows.reduce((sum, row) => sum + Number(row.quantity_on_hand), 0);
                const available = rows.reduce((sum, row) => sum + Number(row.quantity_available), 0);
                return (
                  <button type="button" key={item.id} className={selectedId === item.id ? "inventory-stock-row is-selected" : "inventory-stock-row"} onClick={() => setSelectedId(item.id)}>
                    <span className="inventory-stock-copy"><strong>{item.name}{!item.is_active ? " · غیرفعال" : ""}</strong><small>{item.code} · {item.base_unit.name}</small></span>
                    <span className="inventory-stock-amount"><b>{quantity(onHand)}</b><small>{item.base_unit.code} · قابل استفاده {quantity(available)}</small></span>
                  </button>
                );
              })}
              {!filteredItems.length ? <div className="inventory-empty">{error ? "اطلاعات قابل نمایش نیست." : "قلمی با این جست‌وجو یا انبارک پیدا نشد."}</div> : null}
            </div>
          )}
        </Surface>

        <Surface padding="md" className="inventory-detail-panel">
          {selected ? (
            <>
              <div className="inventory-detail-heading"><div><span className="ui-eyebrow">LIVE STOCK DETAILS</span><h2>{selected.name}</h2><p>{selected.code} · {selected.base_unit.name}</p></div><Package size={20} /></div>
              <div className="inventory-balance"><span>موجودی در انبارک انتخاب‌شده</span><strong>{quantity(selectedBalances.reduce((sum, row) => sum + Number(row.quantity_on_hand), 0))} <small>{selected.base_unit.code}</small></strong></div>
              <div className="inventory-threshold"><span>رزروشده</span><b>{quantity(selectedBalances.reduce((sum, row) => sum + Number(row.quantity_reserved), 0))} {selected.base_unit.code}</b></div>
              <div className="inventory-threshold"><span>قابل استفاده</span><b>{quantity(selectedBalances.reduce((sum, row) => sum + Number(row.quantity_available), 0))} {selected.base_unit.code}</b></div>
              <div className="inventory-history-heading"><strong><Activity size={15} /> گردش‌های ثبت‌شده</strong><span>{quantity(selectedLedger.length)} مورد اخیر</span></div>
              <div className="inventory-history">
                {selectedLedger.map((entry, index) => (
                  <div className="inventory-movement-row" key={`${entry.document_id ?? "ledger"}-${entry.created_at}-${entry.stock_item_id}-${entry.location_id}-${index}`}>
                    <span className={Number(entry.quantity_delta) >= 0 ? "inventory-movement-icon is-in" : "inventory-movement-icon is-out"}><Activity size={15} /></span>
                    <div><strong>{movementLabel[entry.movement_type] ?? entry.movement_type}</strong><small>{entry.location_name} · {dateTime(entry.created_at)}{entry.document_no ? ` · سند ${entry.document_no}` : ""}</small>{entry.note ? <small>{entry.note}</small> : null}</div>
                    <b>{Number(entry.quantity_delta) > 0 ? "+" : ""}{quantity(entry.quantity_delta)} {entry.base_unit_code}</b>
                  </div>
                ))}
                {!selectedLedger.length ? <div className="inventory-empty">برای این قلم در بازه اخیر گردش ثبت‌شده‌ای وجود ندارد.</div> : null}
              </div>
              {ledgerError ? <Notice variant="warning">{ledgerError}</Notice> : null}
              {ledgerCursor ? <div className="inventory-ledger-more"><Button variant="secondary" onClick={() => void loadMoreLedger()} disabled={ledgerLoadingMore}>{ledgerLoadingMore ? "در حال دریافت..." : "بارگذاری گردش‌های قدیمی‌تر"}</Button></div> : null}
            </>
          ) : <div className="inventory-empty">{loading ? "در حال بارگذاری..." : "یک قلم را از فهرست انتخاب کنید."}</div>}
          {selectedBalances.length > 1 ? <div className="inventory-location-breakdown"><strong><MapPin size={14} /> موجودی به تفکیک انبارک</strong>{selectedBalances.map((balance) => <div key={balance.location_id}><span>{balance.location_name}</span><b>{quantity(balance.quantity_on_hand)} {balance.base_unit_code}</b></div>)}</div> : null}
        </Surface>
      </div>
    </div>
  );
}
