#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""验证官方 ip2region_v4.xdb / v6.xdb 格式解析与查询（对照官方 PHP Searcher 逻辑）"""
import struct
import ipaddress

HDR = 256
VEC_ROWS, VEC_COLS, VEC_SIZE = 256, 256, 8
VEC_LEN = VEC_ROWS * VEC_COLS * VEC_SIZE  # 524288


def load_header(path):
    with open(path, "rb") as f:
        b = f.read(20)
    return {
        "version": struct.unpack("<H", b[0:2])[0],
        "indexPolicy": struct.unpack("<H", b[2:4])[0],
        "createdAt": struct.unpack("<I", b[4:8])[0],
        "startIndexPtr": struct.unpack("<I", b[8:12])[0],
        "endIndexPtr": struct.unpack("<I", b[12:16])[0],
        "ipVersion": struct.unpack("<H", b[16:18])[0],
        "runtimePtrBytes": struct.unpack("<H", b[18:20])[0],
    }


def load_vec_index(path):
    with open(path, "rb") as f:
        f.seek(HDR)
        return f.read(VEC_LEN)


def read_at(path, off, ln):
    with open(path, "rb") as f:
        f.seek(off)
        return f.read(ln)


def parse_ip(text, ver):
    if ver == 4:
        return int(ipaddress.IPv4Address(text))
    return ipaddress.IPv6Address(text).packed


def to_bin(ip_bin, ver):
    """转为 xdb 段索引同序的字节：v4 用 LE u32，v6 用原字节"""
    if ver == 4:
        return struct.pack("<I", ip_bin)
    return ip_bin


def search(path, hdr, vec, ip_bin, ver):
    idx_size = 14 if ver == 4 else 38
    bytes_len = 4 if ver == 4 else 16
    key = to_bin(ip_bin, ver)
    il0 = key[0]
    il1 = key[1]
    idx = il0 * VEC_COLS * VEC_SIZE + il1 * VEC_SIZE
    s_ptr, e_ptr = struct.unpack("<II", vec[idx:idx + 8])
    if s_ptr == 0 or e_ptr == 0:
        return ""
    l, h = 0, (e_ptr - s_ptr) // idx_size
    data_len = data_ptr = 0
    hit = False
    while l <= h:
        m = (l + h) >> 1
        p = s_ptr + m * idx_size
        buff = read_at(path, p, idx_size)
        # 段索引: [start(LE)][end(LE)][dataLen u16][dataPtr u32]
        if ver == 4:
            start = struct.unpack("<I", buff[0:4])[0]
            end = struct.unpack("<I", buff[4:8])[0]
            if ip_bin < start:
                h = m - 1
            elif ip_bin > end:
                l = m + 1
            else:
                hit = True
        else:
            start = buff[0:16]
            end = buff[16:32]
            if key < start:
                h = m - 1
            elif key > end:
                l = m + 1
            else:
                hit = True
        if hit:
            data_len = struct.unpack("<H", buff[32:34])[0] if ver == 6 else struct.unpack("<H", buff[8:10])[0]
            data_ptr = struct.unpack("<I", buff[34:38])[0] if ver == 6 else struct.unpack("<I", buff[10:14])[0]
            break
    if data_len == 0:
        return ""
    return read_at(path, data_ptr, data_len).decode("utf-8", "replace")


def main():
    for path, ver in (("tools/_src/ip2region_v4.xdb", 4), ("tools/_src/ip2region_v6.xdb", 6)):
        hdr = load_header(path)
        vec = load_vec_index(path)
        print(f"== {path} ==")
        print("header:", hdr, "segIndexSize:", 14 if ver == 4 else 38)
        seg_count = (hdr["endIndexPtr"] - hdr["startIndexPtr"]) // (14 if ver == 4 else 38)
        print("segment count ~", seg_count)
        # 首条段索引验证
        b0 = read_at(path, hdr["startIndexPtr"], 14 if ver == 4 else 38)
        if ver == 4:
            s, e = struct.unpack("<II", b0[0:8])
            print("first seg start/end:", s, e, "->", str(ipaddress.IPv4Address(s)))
        else:
            print("first seg start:", b0[0:16].hex(), "end:", b0[16:32].hex())
        # 抽样查询
        samples = ["114.114.114.114", "8.8.8.8", "1.1.1.1", "255.255.255.255"] if ver == 4 else [
            "2001:4860:4860::8888", "2606:4700:4700::1111", "240e:390:5a00:2000::1"]
        for ip in samples:
            ip_bin = parse_ip(ip, ver)
            r = search(path, hdr, vec, ip_bin, ver)
            print(f"  {ip} -> {r!r}")
        print()


if __name__ == "__main__":
    main()
