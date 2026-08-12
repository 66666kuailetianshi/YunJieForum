#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""扫描 xdb 段索引，按 dataPtr/dataLen 提取文本，收集唯一国家名"""
import struct

HDR = 256


def load_header(path):
    with open(path, "rb") as f:
        b = f.read(20)
    return {
        "startIndexPtr": struct.unpack("<I", b[8:12])[0],
        "endIndexPtr": struct.unpack("<I", b[12:16])[0],
        "ipVersion": struct.unpack("<H", b[16:18])[0],
    }


def extract(path):
    hdr = load_header(path)
    ver = hdr["ipVersion"]
    idx_size = 14 if ver == 4 else 38
    seg = (hdr["endIndexPtr"] - hdr["startIndexPtr"]) // idx_size
    with open(path, "rb") as f:
        f.seek(hdr["startIndexPtr"])
        buff = f.read(seg * idx_size)
    countries = {}
    for i in range(seg):
        off = i * idx_size
        if ver == 4:
            dlen = struct.unpack("<H", buff[off + 8: off + 10])[0]
            dptr = struct.unpack("<I", buff[off + 10: off + 14])[0]
        else:
            dlen = struct.unpack("<H", buff[off + 32: off + 34])[0]
            dptr = struct.unpack("<I", buff[off + 34: off + 38])[0]
        if dlen == 0:
            continue
        with open(path, "rb") as f2:
            f2.seek(dptr)
            s = f2.read(dlen).decode("utf-8", "replace")
        c = s.split("|")[0]
        if c and c != "0":
            countries[c] = countries.get(c, 0) + 1
    return countries, seg


def main():
    all_c = {}
    for p in ("tools/_src/ip2region_v4.xdb", "tools/_src/ip2region_v6.xdb"):
        c, seg = extract(p)
        print(f"== {p}: {seg} segments, {len(c)} unique countries ==")
        for k in sorted(c):
            all_c[k] = all_c.get(k, 0) + c[k]
    print(f"== TOTAL unique: {len(all_c)} ==")
    with open("tools/_src/countries.txt", "w", encoding="utf-8") as f:
        for k in sorted(all_c):
            f.write(k + "\n")
    print("written to tools/_src/countries.txt")


if __name__ == "__main__":
    main()
