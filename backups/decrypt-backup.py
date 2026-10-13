#!/usr/bin/env python3
"""Decrypt a SHEM SHOP snapshot into a NEW local directory. Never writes production."""
import argparse
import io
import pathlib
import zipfile
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

parser = argparse.ArgumentParser()
parser.add_argument('archive', type=pathlib.Path)
parser.add_argument('--key-file', required=True, type=pathlib.Path)
parser.add_argument('--output', required=True, type=pathlib.Path)
args = parser.parse_args()
if args.output.exists():
    parser.error('Output must be a new directory; existing files are never overwritten.')
raw = args.archive.read_bytes()
if raw[:8] != b'SHEMBKP1':
    parser.error('Unsupported archive format.')
key = bytes.fromhex(args.key_file.read_text().strip())
payload = AESGCM(key).decrypt(raw[8:20], raw[20:], b'SHEM-SHOP runtime backup v1')
with zipfile.ZipFile(io.BytesIO(payload)) as archive:
    if archive.testzip() is not None:
        raise ValueError('ZIP integrity check failed.')
    for name in archive.namelist():
        path = pathlib.PurePosixPath(name)
        if path.is_absolute() or '..' in path.parts or '\\' in name:
            raise ValueError('Unsafe archive path.')
    args.output.mkdir(parents=True)
    archive.extractall(args.output)
print('Decrypted and verified locally. No production data was modified.')
