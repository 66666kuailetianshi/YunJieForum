#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""校验：xdb 中全部国家名均被 PHP 映射表覆盖；并对部署库做最终抽样查询"""
import re
import struct
import ipaddress

HDR = 256
VEC_LEN = 256 * 256 * 8


def load_header(f):
    f.seek(0)
    b = f.read(20)
    return {
        "version": struct.unpack("<H", b[0:2])[0],
        "startIndexPtr": struct.unpack("<I", b[8:12])[0],
        "endIndexPtr": struct.unpack("<I", b[12:16])[0],
        "ipVersion": struct.unpack("<H", b[16:18])[0],
    }


def load_vec(f):
    f.seek(HDR)
    return f.read(VEC_LEN)


def read_at(f, off, ln):
    f.seek(off)
    return f.read(ln)


def iter_countries(f, ip_version):
    seg_size = 14 if ip_version == 4 else 38
    seen = set()
    hdr = load_header(f)
    p = hdr["startIndexPtr"]
    end = hdr["endIndexPtr"]
    while p < end:
        row = read_at(f, p, seg_size)
        if len(row) != seg_size:
            break
        if ip_version == 4:
            dlen = struct.unpack("<H", row[8:10])[0]
            dptr = struct.unpack("<I", row[10:14])[0]
        else:
            dlen = struct.unpack("<H", row[32:34])[0]
            dptr = struct.unpack("<I", row[34:38])[0]
        if dlen:
            txt = read_at(f, dptr, dlen).decode("utf-8", "replace")
            c = txt.split("|", 1)[0]
            if c and c != "0":
                seen.add(c)
        p += seg_size
    return seen


def search(f, hdr, ver, ip_str):
    vec = load_vec(f)
    idx_size = 14 if ver == 4 else 38
    if ver == 4:
        key = ipaddress.IPv4Address(ip_str).packed  # 大端 4 字节，与 inet_pton 一致
    else:
        key = ipaddress.IPv6Address(ip_str).packed
    il0, il1 = key[0], key[1]
    idx = il0 * 256 * 8 + il1 * 8
    s_ptr, e_ptr = struct.unpack("<II", vec[idx:idx + 8])
    if s_ptr == 0 or e_ptr == 0:
        return ""
    l, h = 0, (e_ptr - s_ptr) // idx_size
    while l <= h:
        m = (l + h) >> 1
        p = s_ptr + m * idx_size
        row = read_at(f, p, idx_size)
        if len(row) != idx_size:
            break
        if ver == 4:
            start = struct.unpack("<I", row[0:4])[0]
            end = struct.unpack("<I", row[4:8])[0]
            val = int(ipaddress.IPv4Address(ip_str))
            if val < start:
                h = m - 1
            elif val > end:
                l = m + 1
            else:
                dlen = struct.unpack("<H", row[8:10])[0]
                dptr = struct.unpack("<I", row[10:14])[0]
                return read_at(f, dptr, dlen).decode("utf-8", "replace")
        else:
            start, end = row[0:16], row[16:32]
            if key < start:
                h = m - 1
            elif key > end:
                l = m + 1
            else:
                dlen = struct.unpack("<H", row[32:34])[0]
                dptr = struct.unpack("<I", row[34:38])[0]
                return read_at(f, dptr, dlen).decode("utf-8", "replace")
    return ""


def main():
    php_src = open("app/includes/ip2region.php", encoding="utf-8").read()
    # 提取 PHP 映射表的 key（兼容单引号/双引号包裹，含转义）
    raw = re.findall(r"""'((?:[^'\\]|\\.)*)'\s*=>\s*'|"((?:[^"\\]|\\.)*)"\s*=>\s*'""", php_src)
    map_keys = set()
    for a, b in raw:
        k = a or b
        if len(k) >= 2:
            map_keys.add(k.replace("\\'", "'").replace('\\"', '"').replace("\\\\", "\\"))

    for path, ver in (("app/data/ip2region_v4.xdb", 4), ("app/data/ip2region_v6.xdb", 6)):
        with open(path, "rb") as f:
            hdr = load_header(f)
            print(f"== {path} ==")
            print("header:", hdr)
            countries = iter_countries(f, hdr["ipVersion"])
            missing = sorted(c for c in countries if c not in map_keys)
            print(f"unique countries: {len(countries)}, not in PHP map: {missing}")

    print("\n== 抽样查询（部署库） ==")
    for ip in ["8.8.8.8", "1.1.1.1", "114.114.114.114", "223.5.5.5",
               "2606:4700:4700::1111", "2001:4860:4860::8888", "240e:390:5a00:2000::1"]:
        ver = 6 if ":" in ip else 4
        with open(f"app/data/ip2region_v{ver}.xdb", "rb") as f:
            hdr = load_header(f)
            r = search(f, hdr, ver, ip)
        print(f"  {ip} -> {r!r}")


if __name__ == "__main__":
    main()
