#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""解析 xdb 文件头部结构（只读探查，用于确认官方 ip2region xdb 格式）"""
import struct
import sys


def peek(path, n=320):
    with open(path, "rb") as f:
        head = f.read(n)
    print(f"== {path} ==")
    print("bytes:", head[:64].hex(" "))
    # 前 4B 尝试解析为 u32 version
    print("u32[0..4]   =", struct.unpack("<I", head[0:4])[0])
    print("u32[4..8]   =", struct.unpack("<I", head[4:8])[0])
    print("u32[8..12]  =", struct.unpack("<I", head[8:12])[0])
    print("u32[12..16] =", struct.unpack("<I", head[12:16])[0])
    print("u32[16..20] =", struct.unpack("<I", head[16:20])[0])
    # 常见：从 256 处开始是索引控制信息
    if len(head) >= 264:
        print("u32[256..260] =", struct.unpack("<I", head[256:260])[0], "(startIndex?)")
        print("u32[260..264] =", struct.unpack("<I", head[260:264])[0], "(indexLen?)")
        print("u32[264..268] =", struct.unpack("<I", head[264:268])[0], "(first startIP?)")
    print()


if __name__ == "__main__":
    for p in sys.argv[1:]:
        peek(p)
