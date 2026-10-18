#!/usr/bin/env python3
"""Staging-only helper. The staging containers have no internet; this session reaches the internet through a local policy proxy.
This forwarder listens ONLY on the docker bridge address and relays HTTPS CONNECT tunnels to the session proxy,
and ONLY for *.paypal.com / *.paypalobjects.com on port 443. Everything else is refused.
Usage: python3 paypal-proxy-forwarder.py <bind-ip> <port> <upstream-host> <upstream-port>"""
import asyncio, sys
bind, port, uh, up = sys.argv[1], int(sys.argv[2]), sys.argv[3], int(sys.argv[4])
OK = ('.paypal.com', '.paypalobjects.com')
async def pipe(r, w):
    try:
        while (d := await r.read(65536)): w.write(d); await w.drain()
    except Exception: pass
    finally:
        try: w.close()
        except Exception: pass
async def handle(cr, cw):
    try:
        head = await asyncio.wait_for(cr.readuntil(b'\r\n\r\n'), 10)
        line = head.split(b'\r\n', 1)[0].decode('latin1').split()
        if len(line) < 2 or line[0] != 'CONNECT': raise ValueError('method')
        host, _, p = line[1].rpartition(':')
        if p != '443' or not (('.' + host.lower()).endswith(OK)): raise ValueError('host')
        ur, uw = await asyncio.open_connection(uh, up)
        uw.write(head); await uw.drain()
        await asyncio.gather(pipe(cr, uw), pipe(ur, cw))
    except Exception:
        cw.write(b'HTTP/1.1 403 Forbidden\r\nContent-Length: 0\r\n\r\n')
        try: await cw.drain()
        except Exception: pass
        cw.close()
async def main():
    s = await asyncio.start_server(handle, bind, port)
    async with s: await s.serve_forever()
asyncio.run(main())
