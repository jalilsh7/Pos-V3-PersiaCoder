import { useMemo } from "react";
import { qrcode } from "@/lib/vendor/qrcode-generator";

type ReceiptQrProps = { value: string; size?: number };
type QRCodeInstance = { addData: (input: string, mode?: string) => void; make: () => void; getModuleCount: () => number; isDark: (row: number, col: number) => boolean };
type QRFactory = ((typeNumber: number, level: string) => QRCodeInstance) & { stringToBytes: (input: string) => number[] };

const qrFactory = qrcode as unknown as QRFactory;
qrFactory.stringToBytes = (input: string) => Array.from(new TextEncoder().encode(input));

export function ReceiptQr({ value, size = 112 }: ReceiptQrProps) {
  const matrix = useMemo(() => {
    const code = qrFactory(0, "M");
    code.addData(value, "Byte");
    code.make();
    const count = code.getModuleCount();
    const cells: Array<[number, number]> = [];
    for (let row = 0; row < count; row += 1) {
      for (let col = 0; col < count; col += 1) {
        if (code.isDark(row, col)) cells.push([row, col]);
      }
    }
    return { count, cells };
  }, [value]);

  return (
    <svg
      width={size}
      height={size}
      viewBox={`-4 -4 ${matrix.count + 8} ${matrix.count + 8}`}
      role="img"
      aria-label="کد QR فاکتور"
      shapeRendering="crispEdges"
      className="mx-auto bg-white"
    >
      <rect x={-4} y={-4} width={matrix.count + 8} height={matrix.count + 8} fill="#fff" />
      {matrix.cells.map(([row, col]) => (
        <rect key={`${row}-${col}`} x={col} y={row} width="1" height="1" fill="#111" />
      ))}
    </svg>
  );
}
