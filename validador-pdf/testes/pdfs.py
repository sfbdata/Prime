"""PDFs de teste escritos à mão (tabela xref clássica), acréscimos incrementais e assinatura pelo pyHanko."""

from __future__ import annotations

import io
import re

from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import signers
from pyhanko.sign.fields import SigSeedSubFilter
from pyhanko_certvalidator.registry import SimpleCertificateStore
from pyhanko.sign.timestamps.dummy_client import DummyTimeStamper

from .pki import Entidade

CONTEUDO_ORIGINAL = b'BT /F1 24 Tf 72 720 Td (Contrato original) Tj ET'
CONTEUDO_TROCADO = b'BT /F1 24 Tf 72 720 Td (Contrato TROCADO) Tj ET'


def fluxo(conteudo: bytes) -> bytes:
    return b'<< /Length %d >>\nstream\n' % len(conteudo) + conteudo + b'\nendstream'


def pdf_simples(*, producer: str = 'BlueJus', escondido: bytes | None = None) -> bytes:
    """Uma página com texto. `escondido`: fluxo 7 0 obj que nada usa (preparado para a sombra)."""
    objetos = {
        1: b'<< /Type /Catalog /Pages 2 0 R >>',
        2: b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        3: (
            b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] '
            b'/Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>'
        ),
        4: fluxo(CONTEUDO_ORIGINAL),
        5: b'<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        6: b'<< /Title (Documento BlueJus TESTE) /Producer (' + producer.encode('latin-1') + b') >>',
    }
    if escondido is not None:
        objetos[7] = fluxo(escondido)
    return montar(objetos, raiz=1, info=6)


def montar(objetos: dict[int, bytes], *, raiz: int, info: int | None) -> bytes:
    saida = bytearray(b'%PDF-1.7\n%\xe2\xe3\xcf\xd3\n')
    posicoes: dict[int, int] = {}
    for numero in sorted(objetos):
        posicoes[numero] = len(saida)
        saida += b'%d 0 obj\n' % numero + objetos[numero] + b'\nendobj\n'
    tamanho = max(objetos) + 1
    inicio_do_xref = len(saida)
    saida += b'xref\n0 %d\n0000000000 65535 f \n' % tamanho
    for numero in range(1, tamanho):
        saida += b'%010d 00000 n \n' % posicoes[numero]
    saida += b'trailer\n<< /Size %d /Root %d 0 R' % (tamanho, raiz)
    if info is not None:
        saida += b' /Info %d 0 R' % info
    saida += b' >>\nstartxref\n%d\n%%%%EOF\n' % inicio_do_xref
    return bytes(saida)


def acrescentar(pdf: bytes, objetos: dict[int, bytes], *, raiz: int = 1, info: int | None = 6) -> bytes:
    """Acréscimo incremental (xref clássico) que redefine ou cria os objetos dados."""
    anterior = int(re.findall(rb'startxref\s+(\d+)', pdf)[-1])
    tamanho_anterior = int(re.findall(rb'/Size (\d+)', pdf)[-1])
    saida = bytearray(pdf if pdf.endswith(b'\n') else pdf + b'\n')
    posicoes: dict[int, int] = {}
    for numero in sorted(objetos):
        posicoes[numero] = len(saida)
        saida += b'%d 0 obj\n' % numero + objetos[numero] + b'\nendobj\n'
    inicio_do_xref = len(saida)
    saida += b'xref\n'
    for numero in sorted(posicoes):
        saida += b'%d 1\n%010d 00000 n \n' % (numero, posicoes[numero])
    tamanho = max(tamanho_anterior, max(objetos) + 1)
    saida += b'trailer\n<< /Size %d /Root %d 0 R /Prev %d' % (tamanho, raiz, anterior)
    if info is not None:
        saida += b' /Info %d 0 R' % info
    saida += b' >>\nstartxref\n%d\n%%%%EOF\n' % inicio_do_xref
    return bytes(saida)


def assinar(
    pdf: bytes,
    folha: Entidade,
    *,
    campo: str = 'Signature1',
    cadeia: list[Entidade] | None = None,
    act: Entidade | None = None,
) -> bytes:
    """Assina com PAdES (acréscimo do pyHanko). `cadeia`: certificados que vão dentro do CMS."""
    assinante = signers.SimpleSigner(
        signing_cert=folha.asn1,
        signing_key=folha.chave_asn1,
        cert_registry=SimpleCertificateStore.from_certs([c.asn1 for c in (cadeia or [])]),
    )
    carimbador = DummyTimeStamper(tsa_cert=act.asn1, tsa_key=act.chave_asn1) if act else None
    saida = io.BytesIO()
    signers.sign_pdf(
        IncrementalPdfFileWriter(io.BytesIO(pdf)),
        signers.PdfSignatureMetadata(field_name=campo, subfilter=SigSeedSubFilter.PADES),
        signer=assinante,
        timestamper=carimbador,
        output=saida,
    )
    return saida.getvalue()
