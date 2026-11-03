#!/usr/bin/env python3
"""Ambiente controlado para testar o "Importar pastas em lote" do Expediente.

Gera, numa pasta (padrão ~/lote-teste), 10 cenários de organização de pastas com arquivos FALSOS e
pequenos (PDF, JPG e DOCX mínimos e válidos), nomes fictícios e nenhum dado real. Cada cenário tem:

- a pasta para escolher no modal (botão "Selecionar a pasta dos clientes");
- LEIA-ME.md: o que escolher, o que o sistema cria hoje (pela regra do montar() do
  app/public/js/expediente-importar-lote.js, com a pergunta D11) e o que seria o ideal;
- esperado.json: o mesmo em formato de máquina, para medir a IA da etapa 2 (spec
  docs/specs/importar-pastas-em-lote.md, PR #600) contra o "ideal".

O resultado "hoje" é calculado por uma cópia em Python do montar() (função montar abaixo). Se o
montar() do JS mudar, atualize a cópia.

Uso:  python3 scripts/lote-teste/gerar.py [pasta-de-destino]
Só a biblioteca padrão do Python. Pasta de destino existente e não vazia: o script para, sem apagar nada.
"""

from __future__ import annotations

import base64
import io
import json
import re
import sys
import unicodedata
import zipfile
from pathlib import Path

# JPEG 64x40 de linha de base (gerado uma vez com o Pillow; nenhum dado real).
JPG = base64.b64decode(
    "/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAoHBwgHBgoICAgLCgoLDhgQDg0NDh0VFhEYIx8lJCIfIiEmKzcvJik0KSEiMEExNDk7Pj4+JS5ESUM8SDc9Pjv/2wBDAQoLCw4NDhwQEBw7KCIoOzs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozs7Ozv/wAARCAAoAEADASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwD0S7u7mK8htbW3ileWN5CZZjGAFKjsrZ+/+lN87V/+fGy/8DH/APjVE3/IftP+vWf/ANCiq9XRoktDn1bepR87V/8Anxsv/Ax//jVHnav/AM+Nl/4GP/8AGqvUUrrsPlff8ij52r/8+Nl/4GP/APGqPO1f/nxsv/Ax/wD41V6ii67Byvv+RR87V/8Anxsv/Ax//jVOtLu5lvJrW6t4onijSQGKYyAhiw7quPufrVyqMP8AyH7v/r1g/wDQpaejT0FqmtQm/wCQ/af9es//AKFFWZqllaI2sutrCrLpwYERgEE+dk/XitOb/kP2n/XrP/6FFV6mpctv66kuClf+uhgymyks12HTrdRMCwiIkhb5TgSYC49s9wPpSLa2lymlSPZQ/NcOhDAOCojlxgkcrwCPbFb9FHOHs+5h6KbVbqZBFYrKLifDLIPO/wBY3BXHAx79K3KKKmTu7lwjyqwVRh/5D93/ANesH/oUtXqow/8AIfu/+vWD/wBCloWzCW6/roOu7S5lvIbq1uIonijeMiWEyAhip7MuPufrTfJ1f/n+sv8AwDf/AOO0UUczDlV7h5Or/wDP9Zf+Ab//AB2jydX/AOf6y/8AAN//AI7RRRzMORf02Hk6v/z/AFl/4Bv/APHaPJ1f/n+sv/AN/wD47RRRzMORf02Hk6v/AM/1l/4Bv/8AHadaWlzFeTXV1cRSvLGkYEUJjAClj3Zs/f8A0ooo5mHKr3P/2Q=="
)

TETO_IMAGEM = 3 * 1024 * 1024  # UploadPecaUseCase: imagem até 3 MB.


# ---------------------------------------------------------------- arquivos falsos

def _ascii(texto: str) -> str:
    sem_acento = unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode()
    return re.sub(r"[()\\]", "", sem_acento)


def pdf(rotulo: str) -> bytes:
    """PDF 1.4 de uma página, com xref correto, escrito à mão (sem biblioteca)."""
    texto = f"BT /F1 11 Tf 12 60 Td (ARQUIVO FICTICIO DE TESTE) Tj 0 -16 Td ({_ascii(rotulo)[:60]}) Tj ET".encode()
    objetos = [
        b"<</Type/Catalog/Pages 2 0 R>>",
        b"<</Type/Pages/Kids[3 0 R]/Count 1>>",
        b"<</Type/Page/Parent 2 0 R/MediaBox[0 0 360 100]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>",
        b"<</Length " + str(len(texto)).encode() + b">>stream\n" + texto + b"\nendstream",
        b"<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>",
    ]
    saida = io.BytesIO()
    saida.write(b"%PDF-1.4\n")
    posicoes = []
    for n, corpo in enumerate(objetos, start=1):
        posicoes.append(saida.tell())
        saida.write(f"{n} 0 obj\n".encode() + corpo + b"\nendobj\n")
    inicio_xref = saida.tell()
    saida.write(f"xref\n0 {len(objetos) + 1}\n0000000000 65535 f \n".encode())
    for p in posicoes:
        saida.write(f"{p:010d} 00000 n \n".encode())
    saida.write(f"trailer\n<</Size {len(objetos) + 1}/Root 1 0 R>>\nstartxref\n{inicio_xref}\n%%EOF\n".encode())

    return saida.getvalue()


def docx(rotulo: str) -> bytes:
    """DOCX mínimo: [Content_Types].xml declara a parte principal (o upload confere isso por dentro)."""
    tipos = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        '<Default Extension="xml" ContentType="application/xml"/>'
        '<Override PartName="/word/document.xml" '
        'ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        "</Types>"
    )
    rels = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" '
        'Target="word/document.xml"/></Relationships>'
    )
    documento = (
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
        f"<w:p><w:r><w:t>Arquivo fictício de teste: {rotulo}</w:t></w:r></w:p>"
        "</w:body></w:document>"
    )
    saida = io.BytesIO()
    with zipfile.ZipFile(saida, "w", zipfile.ZIP_DEFLATED) as z:
        z.writestr("[Content_Types].xml", tipos)
        z.writestr("_rels/.rels", rels)
        z.writestr("word/document.xml", documento)

    return saida.getvalue()


def jpg_grande() -> bytes:
    """JPEG válido acima do teto de imagem (3 MB): segmentos de comentário (COM) logo depois do SOI."""
    pedaco = b"\x00" * 65533
    com = b"\xff\xfe" + (65535).to_bytes(2, "big") + pedaco
    vezes = TETO_IMAGEM // len(com) + 2

    return JPG[:2] + com * vezes + JPG[2:]


def conteudo(nome: str) -> bytes:
    """Arquivo falso pelo nome. Lixo do sistema e o executável são só bytes de mentira."""
    baixo = nome.lower()
    if baixo.endswith(".pdf"):
        return pdf(nome)
    if baixo.endswith(".docx"):
        return docx(nome)
    if baixo.endswith(".jpg"):
        return jpg_grande() if "grande" in baixo else JPG
    if baixo.endswith(".exe"):
        return b"MZ" + b"\x00" * 62 + b"arquivo ficticio de teste, nao executa"

    return b"arquivo ficticio de teste\n"


# ---------------------------------------------------------------- regra de hoje (cópia do montar() do JS)

IGNORAR = re.compile(r"^(\.|thumbs\.db$|desktop\.ini$)", re.IGNORECASE)


def _nome_do_cliente(chave: str) -> str:
    return re.sub(r"\s+", " ", re.sub(r"[_-]+", " ", chave)).strip().upper()


def _ordem_pt(texto: str) -> str:
    return unicodedata.normalize("NFKD", texto).encode("ascii", "ignore").decode().lower()


def precisa_perguntar(caminhos: list[str]) -> bool:
    """D11: arquivo solto direto na pasta escolhida E arquivo em subpasta."""
    validos = [c.split("/") for c in caminhos if not IGNORAR.search(c.split("/")[-1])]

    return any(len(p) == 2 for p in validos) and any(len(p) > 2 for p in validos)


def montar(caminhos: list[str], um_cliente: bool) -> list[dict]:
    """Mesmo agrupamento do montar(files, false, umCliente) do JS. Caminho = webkitRelativePath."""
    grupos: dict[str, list[str]] = {}
    for c in caminhos:
        partes = c.split("/")
        if IGNORAR.search(partes[-1]):
            continue
        if um_cliente:
            chave = partes[0]
        else:
            chave = partes[1] if len(partes) > 2 else partes[0] if len(partes) == 2 else "NOVA PASTA"
        grupos.setdefault(chave, []).append(c)
    pastas = []
    for chave in sorted(grupos, key=_ordem_pt):
        arquivos = []
        for c in grupos[chave]:
            partes = c.split("/")
            item = {"arquivo": partes[-1]}
            if um_cliente and len(partes) > 2:
                item["descricao"] = "/".join(partes[1:])
            arquivos.append(item)
        pastas.append({"cliente": _nome_do_cliente(chave), "arquivos": arquivos})

    return pastas


# ---------------------------------------------------------------- cenários

# Cada cenário: pasta para escolher (dentro do cenário), arquivos (caminhos relativos a ela), pastas vazias,
# o que seria o ideal (pasta = caso; o mesmo cliente pode ter várias pastas) e notas.
CENARIOS = [
    {
        "id": "01-plano",
        "titulo": "Plano: Clientes/Cliente/arquivos",
        "escolher": "Clientes",
        "arquivos": ["ANA_SOUZA_TESTE/procuracao.pdf", "ANA_SOUZA_TESTE/RG.jpg",
                     "BRUNO-LIMA-TESTE/contrato.docx", "CARLA MENDES TESTE/inicial.pdf"],
        "ideal": "3 pastas: ANA SOUZA TESTE (2 arquivos), BRUNO LIMA TESTE (1), CARLA MENDES TESTE (1).",
        "notas": "Caso feliz. Sem pergunta.",
    },
    {
        "id": "02-cliente-com-subpastas-e-soltos",
        "titulo": "Um cliente com subpastas (Documentos, Processo) e arquivos soltos",
        "escolher": "MARIA FICTICIA",
        "arquivos": ["procuracao.pdf", "Documentos/RG.jpg", "Documentos/comprovante.pdf",
                     "Processo/Inicial.pdf", "Processo/contestacao.docx"],
        "ideal": "1 pasta MARIA FICTICIA com 5 arquivos; a descrição leva o caminho (Documentos/RG.jpg, Processo/Inicial.pdf...).",
        "notas": "Aparece a pergunta D11. Resposta certa: Sim.",
        "resposta_certa": "sim",
    },
    {
        "id": "03-cliente-so-com-subpastas",
        "titulo": "Um cliente só com subpastas, sem arquivo solto",
        "escolher": "PAULO FICTICIO",
        "arquivos": ["Documentos/RG.jpg", "Documentos/CPF.pdf", "Processo/Inicial.pdf"],
        "ideal": "1 pasta PAULO FICTICIO com 3 arquivos.",
        "notas": "Limite da regra: sem arquivo solto não há pergunta, e cada subpasta vira um cliente "
                 "(DOCUMENTOS e PROCESSO). Caso para a IA da etapa 2.",
    },
    {
        "id": "04-numeradas",
        "titulo": "Pastas numeradas (\"001 - Fulano\")",
        "escolher": "Clientes",
        "arquivos": ["001 - Fulano Ficticio/inicial.pdf", "002 - Beltrana_Teste/procuracao.pdf",
                     "010 - Sicrano Teste/RG.jpg"],
        "ideal": "3 pastas com o nome sem o número: FULANO FICTICIO, BELTRANA TESTE, SICRANO TESTE.",
        "notas": "Hoje o número fica no nome do cliente (001 FULANO FICTICIO): a pessoa corrige na tabela.",
    },
    {
        "id": "05-tres-niveis",
        "titulo": "Três níveis (Escritório/Ano/Cliente/Processo)",
        "escolher": "Escritorio",
        "arquivos": ["2024/JOAO FICTICIO/Processo 1/inicial.pdf", "2024/JOAO FICTICIO/Processo 1/sentenca.pdf",
                     "2024/LUIZA TESTE/Processo A/inicial.pdf", "2025/JOAO FICTICIO/Processo 2/inicial.pdf"],
        "ideal": "3 pastas (pasta = caso): JOAO FICTICIO (Processo 1, 2 arquivos), LUIZA TESTE (Processo A), "
                 "JOAO FICTICIO (Processo 2). As duas do João são casos diferentes e NÃO se juntam.",
        "notas": "Limite da regra: o ano vira cliente (2024 e 2025). Caso para a IA.",
    },
    {
        "id": "06-soltos-e-subpastas-de-varios-clientes",
        "titulo": "Pasta de vários clientes com um arquivo solto",
        "escolher": "Clientes",
        "arquivos": ["planilha-geral.pdf", "ANA TESTE/procuracao.pdf", "BRUNO TESTE/RG.jpg"],
        "ideal": "2 pastas (ANA TESTE, BRUNO TESTE) e o planilha-geral.pdf fora, para a pessoa decidir.",
        "notas": "Aparece a pergunta D11. Resposta certa: Não (vira ANA TESTE, BRUNO TESTE e CLIENTES com o solto; "
                 "desmarque CLIENTES se não quiser importar o solto). Sim juntaria clientes diferentes.",
        "resposta_certa": "nao",
    },
    {
        "id": "07-lixo-e-pastas-vazias",
        "titulo": "Lixo do sistema (Thumbs.db, desktop.ini, ocultos) e pastas vazias",
        "escolher": "Clientes",
        "arquivos": ["Thumbs.db", "desktop.ini", ".DS_Store", "ANA TESTE/procuracao.pdf", "ANA TESTE/Thumbs.db",
                     "ANA TESTE/.~lock.contrato.docx#", ".ocultos/rascunho.pdf"],
        "vazias": ["PASTA VAZIA", "ANA TESTE/Subpasta vazia"],
        "ideal": "1 pasta ANA TESTE com 1 arquivo. Lixo, oculto e pasta vazia não aparecem.",
        "notas": "O lixo na raiz não dispara a pergunta. Pasta vazia o navegador nem lista. "
                 "Achado: o montar() ignora só ARQUIVO oculto; a PASTA oculta .ocultos vira um cliente \".OCULTOS\" "
                 "(veja \"hoje\"). Provável defeito.",
    },
    {
        "id": "08-acentos-e-separadores",
        "titulo": "Acentos, _ e -",
        "escolher": "Clientes",
        "arquivos": ["JOSÉ_DA_CONCEIÇÃO/procuração.pdf", "ÁUREA-MÜLLER/RG.jpg", "édson lima/inicial.pdf",
                     "Zé__Ninguém--Teste/contrato.docx"],
        "ideal": "4 pastas, em ordem alfabética do português: ÁUREA MÜLLER, ÉDSON LIMA, JOSÉ DA CONCEIÇÃO, ZÉ NINGUÉM TESTE.",
        "notas": "Confere a ordem (acento não joga para o fim) e a troca de _/- por espaço.",
    },
    {
        "id": "09-mesmo-cliente-dois-casos",
        "titulo": "Mesmo cliente em dois casos",
        "escolher": "Clientes",
        "arquivos": ["PEDRO FICTICIO - TRABALHISTA/inicial.pdf", "PEDRO FICTICIO - TRABALHISTA/ctps.jpg",
                     "PEDRO FICTICIO - FAMILIA/inicial.pdf"],
        "ideal": "2 pastas separadas (pasta = caso). A IA pode avisar \"mesmo cliente que a pasta X\", sem juntar.",
        "notas": "Hoje vira PEDRO FICTICIO FAMILIA e PEDRO FICTICIO TRABALHISTA: certo; só o nome leva o tipo do caso.",
    },
    {
        "id": "10-recusados",
        "titulo": "Arquivo grande demais e tipo recusado",
        "escolher": "Clientes",
        "arquivos": ["RITA TESTE/ok.pdf", "RITA TESTE/contrato.docx", "RITA TESTE/foto-grande.jpg",
                     "RITA TESTE/instalador.exe"],
        "ideal": "1 pasta RITA TESTE com 2 documentos anexados; 2 recusados (imagem acima de 3 MB e executável), "
                 "contados na mensagem final sem parar o lote.",
        "notas": "foto-grande.jpg tem ~3,3 MB (JPEG válido). instalador.exe é falso (só o cabeçalho MZ), não executa.",
    },
]


def esperado(c: dict) -> dict:
    caminhos = [f"{c['escolher']}/{a}" for a in c["arquivos"]]
    pergunta = precisa_perguntar(caminhos)
    saida = {
        "id": c["id"],
        "titulo": c["titulo"],
        "escolher": c["escolher"],
        "caminhos": caminhos,
        "pergunta": pergunta,
        "ideal": c["ideal"],
        "notas": c["notas"],
    }
    if pergunta:
        saida["resposta_certa"] = c.get("resposta_certa")
        saida["hoje_sim"] = montar(caminhos, True)
        saida["hoje_nao"] = montar(caminhos, False)
    else:
        saida["hoje"] = montar(caminhos, False)

    return saida


def _tabela(pastas: list[dict]) -> str:
    linhas = ["| Pasta (cliente) | Arquivos |", "|---|---|"]
    for p in pastas:
        arqs = ", ".join(a["arquivo"] + (f" (descrição: {a['descricao']})" if "descricao" in a else "") for a in p["arquivos"])
        linhas.append(f"| {p['cliente']} | {arqs} |")

    return "\n".join(linhas)


def leia_me(e: dict) -> str:
    partes = [
        f"# {e['id']}: {e['titulo']}",
        "",
        f"Escolha no modal a pasta **{e['escolher']}** (dentro desta pasta do cenário).",
        "Arquivos fictícios, sem nenhum dado real.",
        "",
        "## O ideal",
        "",
        e["ideal"],
        "",
    ]
    if e["pergunta"]:
        partes += [
            "## Hoje (regra do montar() com a pergunta D11)",
            "",
            f"Aparece a pergunta \"Esta é a pasta de um cliente só?\". Resposta certa: **{e['resposta_certa']}**.",
            "",
            "Respondendo **Sim**:",
            "",
            _tabela(e["hoje_sim"]),
            "",
            "Respondendo **Não**:",
            "",
            _tabela(e["hoje_nao"]),
            "",
        ]
    else:
        partes += ["## Hoje (regra do montar(), sem pergunta)", "", _tabela(e["hoje"]), ""]
    partes += ["## Observação", "", e["notas"], ""]

    return "\n".join(partes)


def gerar(destino: Path) -> None:
    if destino.exists() and any(destino.iterdir()):
        sys.exit(f"{destino} já existe e não está vazia. Escolha outra pasta ou apague-a antes (o script não apaga nada).")
    destino.mkdir(parents=True, exist_ok=True)
    for c in CENARIOS:
        base = destino / c["id"]
        raiz = base / c["escolher"]
        for rel in c["arquivos"]:
            alvo = raiz / rel
            alvo.parent.mkdir(parents=True, exist_ok=True)
            alvo.write_bytes(conteudo(alvo.name))
        for vazia in c.get("vazias", []):
            (raiz / vazia).mkdir(parents=True, exist_ok=True)
        e = esperado(c)
        (base / "LEIA-ME.md").write_text(leia_me(e), encoding="utf-8")
        (base / "esperado.json").write_text(json.dumps(e, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"{len(CENARIOS)} cenários gerados em {destino}")


if __name__ == "__main__":
    gerar(Path(sys.argv[1] if len(sys.argv) > 1 else "~/lote-teste").expanduser())
