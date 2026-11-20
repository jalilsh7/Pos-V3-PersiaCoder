import { useState } from "react";
import { Building2, Clock3, Save, Settings2, Store } from "lucide-react";
import { Button, CheckboxField, Notice, SelectField, StatusBadge, Surface, TextField } from "../../shared/ui";
import "./settings.css";

const weekdays = ["شنبه", "یکشنبه", "دوشنبه", "سه‌شنبه", "چهارشنبه", "پنجشنبه", "جمعه"];
const channelLabels = [
  ["dineIn", "سرویس داخل سالن", "ثبت سفارش میز و سالن"],
  ["takeaway", "بیرون‌بر", "تحویل حضوری به مشتری"],
  ["delivery", "ارسال با پیک", "مدیریت سفارش‌های ارسالی"],
  ["online", "سفارش آنلاین", "دریافت سفارش از منوی اینترنتی"],
] as const;

export function BusinessSettingsWorkspace() {
  const [businessName, setBusinessName] = useState("رستوران نمونه");
  const [businessType, setBusinessType] = useState("restaurant");
  const [phone, setPhone] = useState("021-12345678");
  const [address, setAddress] = useState("تهران، خیابان نمونه");
  const [currency, setCurrency] = useState("toman");
  const [channels, setChannels] = useState<Record<string, boolean>>({ dineIn: true, takeaway: true, delivery: true, online: false });
  const [days, setDays] = useState<string[]>(weekdays.slice(0, 6));
  const [openTime, setOpenTime] = useState("09:00");
  const [closeTime, setCloseTime] = useState("23:00");
  const [saved, setSaved] = useState(false);

  function toggleChannel(key: string, checked: boolean) {
    setChannels((current) => ({ ...current, [key]: checked }));
    setSaved(false);
  }
  function toggleDay(day: string, checked: boolean) {
    setDays((current) => checked ? [...current, day] : current.filter((item) => item !== day));
    setSaved(false);
  }
  function savePreview() {
    setSaved(true);
  }

  return (
    <div className="page-stack business-settings">
      <div className="business-settings-heading">
        <div><span className="ui-eyebrow">BUSINESS CONFIGURATION</span><h1>تنظیمات کسب‌وکار</h1><p>مشخصات پایه، روش‌های دریافت سفارش و ساعات فعالیت</p></div>
        <StatusBadge variant={saved ? "success" : "warning"}>{saved ? "در پیش‌نمایش ذخیره شد" : "تغییرات ذخیره‌نشده"}</StatusBadge>
      </div>
      <Notice variant="warning">این صفحه نمونه طراحی تنظیمات است. ذخیره فقط وضعیت محلی رابط را تغییر می‌دهد؛ هیچ تنظیمی در سرور، فروشگاه آنلاین یا صندوق ثبت و اعمال نمی‌شود.</Notice>
      <Surface padding="md" className="business-settings-panel">
        <div className="business-settings-panel-heading"><span className="business-settings-icon"><Building2 size={17} /></span><div><h2>اطلاعات کسب‌وکار</h2><p>مشخصات عمومی و پروفایل صنفی</p></div></div>
        <div className="business-settings-fields">
          <TextField label="نام کسب‌وکار" value={businessName} onChange={(event) => { setBusinessName(event.target.value); setSaved(false); }} />
          <SelectField label="نوع کسب‌وکار" value={businessType} onChange={(event) => { setBusinessType(event.target.value); setSaved(false); }} options={[{value:"restaurant",label:"رستوران"},{value:"cafe",label:"کافه"},{value:"fastfood",label:"فست‌فود"},{value:"juice",label:"آبمیوه‌فروشی"},{value:"icecream",label:"بستنی‌فروشی"}]} />
          <TextField label="تلفن تماس" value={phone} onChange={(event) => { setPhone(event.target.value); setSaved(false); }} />
          <SelectField label="واحد پول نمایشی" value={currency} onChange={(event) => { setCurrency(event.target.value); setSaved(false); }} options={[{value:"toman",label:"تومان"},{value:"rial",label:"ریال"}]} />
          <div className="business-settings-wide"><TextField label="نشانی" value={address} onChange={(event) => { setAddress(event.target.value); setSaved(false); }} /></div>
        </div>
      </Surface>
      <Surface padding="md" className="business-settings-panel">
        <div className="business-settings-panel-heading"><span className="business-settings-icon"><Store size={17} /></span><div><h2>کانال‌های دریافت سفارش</h2><p>روش‌هایی که در این کسب‌وکار فعال هستند</p></div></div>
        <div className="business-settings-options">
          {channelLabels.map(([key, label, description]) => <div className="business-settings-option" key={key}>
            <CheckboxField label={label} description={description} checked={Boolean(channels[key])} onChange={(event) => toggleChannel(key, event.target.checked)} />
            <StatusBadge variant={channels[key] ? "success" : "neutral"}>{channels[key] ? "فعال" : "غیرفعال"}</StatusBadge>
          </div>)}
        </div>
      </Surface>
      <Surface padding="md" className="business-settings-panel">
        <div className="business-settings-panel-heading"><span className="business-settings-icon"><Clock3 size={17} /></span><div><h2>ساعات کاری</h2><p>روزهای فعالیت و بازه زمانی نمونه</p></div></div>
        <div className="business-settings-days">{weekdays.map((day) => <CheckboxField key={day} label={day} checked={days.includes(day)} onChange={(event) => { toggleDay(day, event.target.checked); }} />)}</div>
        <div className="business-settings-hours"><TextField label="ساعت شروع" type="time" value={openTime} onChange={(event) => { setOpenTime(event.target.value); setSaved(false); }} /><TextField label="ساعت پایان" type="time" value={closeTime} onChange={(event) => { setCloseTime(event.target.value); setSaved(false); }} /></div>
        <p className="business-settings-hint">تعطیلی موقت، استثنای روزهای خاص و ساعت جداگانه هر شعبه در تنظیمات تکمیلی قابل طراحی است.</p>
      </Surface>
      <div className="business-settings-footer"><span><Settings2 size={14} /> تنظیمات پیش‌نمایش، بدون اثر عملیاتی</span><Button variant="primary" onClick={savePreview}><Save size={14} /> ذخیره پیش‌نمایش</Button></div>
    </div>
  );
}
