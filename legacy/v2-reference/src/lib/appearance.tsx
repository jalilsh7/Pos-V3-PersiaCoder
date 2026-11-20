import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from "react";
import { phpApi, type AppearanceSettings } from "@/lib/api/php-client";
export const DEFAULT_APPEARANCE: AppearanceSettings = { primary:"#14532d",secondary:"#0f766e",mode:"light",fontFamily:"byekan",fontSize:15,cardRadius:16,buttonRadius:12,logoUrl:null };

export const APPEARANCE_PRESETS = [
 { id:"royal", name:"سبز سلطنتی", description:"اصیل، مطمئن و مناسب فضای رستورانی", primary:"#14532d", secondary:"#0f766e" },
 { id:"ocean", name:"آبی اقیانوسی", description:"آرام، حرفه‌ای و شفاف", primary:"#1d4ed8", secondary:"#0891b2" },
 { id:"violet", name:"بنفش مدرن", description:"خاص، خلاق و امروزی", primary:"#6d28d9", secondary:"#c026d3" },
 { id:"teal", name:"فیروزه‌ای گرم", description:"تازه و پرانرژی با تأکید کهربایی", primary:"#0f766e", secondary:"#d97706" },
 { id:"graphite", name:"زغالی لوکس", description:"مینیمال و شیک با جزئیات طلایی", primary:"#334155", secondary:"#b45309" },
 { id:"orange-white", name:"نارنجی و سفید", description:"مدرن، پرانرژی و روشن؛ مناسب محیط فروش", primary:"#ea580c", secondary:"#f59e0b", mode:"light" },
 { id:"ice-blue", name:"آبی یخی", description:"سفید تمیز با آبی شفاف و حس تکنولوژیک", primary:"#2563eb", secondary:"#06b6d4", mode:"light" },
 { id:"coral", name:"مرجانی و صورتی", description:"گرم، دوستانه و امروزی با زمینه روشن", primary:"#e11d48", secondary:"#f97316", mode:"light" },
] as const;
type AppearanceContextValue={settings:AppearanceSettings;update:(patch:Partial<AppearanceSettings>)=>void;save:()=>Promise<void>;reset:()=>Promise<void>;uploadLogo:(file:File)=>Promise<void>;loading:boolean};
const AppearanceContext=createContext<AppearanceContextValue|null>(null);
function safeColor(value:string,fallback:string){return /^#[0-9a-f]{6}$/i.test(value)?value:fallback;}
function applyAppearance(s:AppearanceSettings){
 const root=document.documentElement,dark=s.mode==="dark";
 const secondary=safeColor(s.secondary,DEFAULT_APPEARANCE.secondary);
 const resolvedSecondary=dark&&secondary.toLowerCase()===DEFAULT_APPEARANCE.secondary?"#5eead4":secondary;
 root.style.colorScheme=dark?"dark":"light";
 const fonts={byekan:'"IRANYekanWeb FarsiNum", Tahoma, sans-serif'};
 root.style.setProperty("--color-chili",safeColor(s.primary,DEFAULT_APPEARANCE.primary));root.style.setProperty("--color-leaf",resolvedSecondary);
 root.style.setProperty("--color-bg",dark?"#111a16":"#f3f7f3");root.style.setProperty("--color-surface",dark?"#1b2821":"#ffffff");root.style.setProperty("--color-ink",dark?"#f0f6f1":"#17251d");
 root.style.setProperty("--color-muted",dark?"#b2c2b7":"#68786d");root.style.setProperty("--color-line",dark?"#52685a":"#e0e9e1");root.style.setProperty("--color-price",dark?"#86efac":safeColor(s.primary,DEFAULT_APPEARANCE.primary));
 root.style.setProperty("--color-leaf-ink",dark?"#14362e":"#f0fdfa");root.style.setProperty("--color-warn",dark?"#fdba74":"#9a3412");root.style.setProperty("--color-warn-bg",dark?"#3b2a1f":"#fff1e6");
 root.style.setProperty("--color-overlay",dark?"rgb(0 0 0 / 0.64)":"rgb(23 37 29 / 0.42)");root.style.setProperty("--shadow-card",dark?"0 14px 36px rgb(0 0 0 / 0.22)":"0 12px 34px rgb(20 45 30 / 0.06)");
 root.style.setProperty("--radius-card",`${Math.min(28,Math.max(8,s.cardRadius))}px`);root.style.setProperty("--radius-2xl",`${Math.min(28,Math.max(8,s.cardRadius))}px`);
 root.style.setProperty("--radius-xl",`${Math.min(24,Math.max(6,s.buttonRadius))}px`);root.style.setProperty("--font-sans",fonts[s.fontFamily]??fonts.byekan);
 root.style.setProperty("--app-font-size",`${Math.min(20,Math.max(13,s.fontSize))}px`);root.dataset.theme=dark?"dark":"light";
}
export function AppearanceProvider({children}:{children:ReactNode}){
 const [settings,setSettings]=useState(DEFAULT_APPEARANCE),[loading,setLoading]=useState(true),dirty=useRef(false);
 useEffect(()=>{applyAppearance(settings);},[settings]);
 useEffect(()=>{let active=true,inFlight=false;const refresh=async()=>{if(!active||inFlight||dirty.current||document.visibilityState==="hidden")return;inFlight=true;try{const r=await phpApi.getAppearance();if(active&&!dirty.current){const loaded={...DEFAULT_APPEARANCE,...r.settings};if(loaded.primary.toLowerCase()==="#c2410c")loaded.primary=DEFAULT_APPEARANCE.primary;if(loaded.secondary.toLowerCase()==="#1f6b4a")loaded.secondary=DEFAULT_APPEARANCE.secondary;setSettings(loaded);}}catch{/* Keep last known theme. */}finally{inFlight=false;if(active)setLoading(false);}};
 void refresh();const timer=window.setInterval(()=>void refresh(),15000);const visible=()=>{if(document.visibilityState==="visible")void refresh();};
 window.addEventListener("focus",visible);document.addEventListener("visibilitychange",visible);
 return()=>{active=false;window.clearInterval(timer);window.removeEventListener("focus",visible);document.removeEventListener("visibilitychange",visible);};},[]);
 const update=(patch:Partial<AppearanceSettings>)=>{dirty.current=true;setSettings(s=>({...s,...patch}));};
 const save=async()=>{const r=await phpApi.saveAppearance(settings);dirty.current=false;setSettings({...DEFAULT_APPEARANCE,...r.settings});};
 const reset=async()=>{const r=await phpApi.resetAppearance();dirty.current=false;const next={...DEFAULT_APPEARANCE,...r.settings};if(next.primary.toLowerCase()==="#c2410c")next.primary=DEFAULT_APPEARANCE.primary;if(next.secondary.toLowerCase()==="#1f6b4a")next.secondary=DEFAULT_APPEARANCE.secondary;setSettings(next);};
 const uploadLogo=async(file:File)=>{if(!["image/png","image/jpeg","image/webp"].includes(file.type))throw Error("لوگو باید PNG، JPG یا WebP باشد.");if(file.size<1||file.size>512*1024)throw Error("حجم لوگو باید حداکثر ۵۱۲ کیلوبایت باشد.");
 const data=await new Promise<string>((resolve,reject)=>{const reader=new FileReader();reader.onerror=()=>reject(Error("خواندن فایل لوگو انجام نشد."));reader.onload=()=>typeof reader.result==="string"?resolve(reader.result):reject(Error("فایل لوگو معتبر نیست."));reader.readAsDataURL(file);});
 const r=await phpApi.uploadAppearanceLogo(data.slice(data.indexOf(",")+1));update({logoUrl:r.logo_url});};
 const value={settings,update,save,reset,uploadLogo,loading};
 return <AppearanceContext.Provider value={value}>{children}</AppearanceContext.Provider>;
}
export function useAppearance(){const value=useContext(AppearanceContext);if(!value)throw Error("useAppearance must be used inside AppearanceProvider");return value;}
