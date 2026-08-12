#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""调试：查看 xdb 数据区原始字节"""
import struct

HDR = 256
VEC_LEN = 256 * 256 * 8


def dump(path):
    with open(path, "rb") as f:
        h = f.read(20)
        start_idx = struct.unpack("<I", h[8:12])[0]
        data_size = start_idx - HDR - VEC_LEN
        print(f"== {path}: data_region size = {data_size} ==")
        f.seek(HDR + VEC_LEN)
        d = f.read(min(600, data_size))
        print("head bytes:", d[:200].hex(" "))
        print("head repr:", repr(d[:300]))
        # 看是否有 \x00 或 \n
        print("count \\x00:", d.count(b"\x00"), " count \\n:", d.count(b"\n"))


for p in ("tools/_src/ip2region_v4.xdb", "tools/_src/ip2region_v6.xdb"):
    dump(p)
