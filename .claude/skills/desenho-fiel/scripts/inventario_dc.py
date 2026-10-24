#!/usr/bin/env python3
"""Inventário de um desenho do Claude Design (.dc.html).

Lê o arquivo e imprime, em Markdown, tudo o que precisa virar item da conferência ✓/✗:
dependências do <helmet>, regras de CSS (inclusive @media e :hover), cores e tamanhos dos
estilos inline, seções (data-screen-label), condicionais (sc-if = estados escondidos),
repetições (sc-for), eventos, estado inicial, textos visíveis, textos de atributo
(title, placeholder, aria-label) e textos que só existem dentro do JavaScript.

Só usa a biblioteca padrão. Roda no host: python3 inventario_dc.py "<arquivo>.dc.html"
"""
import collections
import html.parser
import os
import re
import sys

VAZIAS = {"img", "br", "hr", "input", "meta", "link", "source", "col", "area", "wbr", "path", "circle", "rect", "line", "polyline", "polygon", "use", "stop"}
ATRIBUTOS_DE_TEXTO = ("title", "placeholder", "aria-label", "alt", "value", "label")
COR = re.compile(r"#[0-9a-fA-F]{3,8}\b|rgba?\([^)]*\)|hsla?\([^)]*\)")
MEDIDA = re.compile(
    r"\b(font-size|font-weight|line-height|letter-spacing|padding[a-z-]*|margin[a-z-]*|gap|"
    r"border-radius|border[a-z-]*width|width|height|min-width|max-width|min-height|max-height|"
    r"grid-template-columns|grid-template-rows|box-shadow|border|border-(?:top|right|bottom|left)|outline|"
    r"background|background-color|transition|animation|opacity|transform|text-transform|text-shadow)"
    r"\s*:\s*([^;\"]+)"
)


class Leitor(html.parser.HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.pilha = []
        self.textos = []
        self.atributos = []
        self.condicionais = []
        self.repeticoes = []
        self.eventos = collections.Counter()
        self.secoes = []
        self.dependencias = []
        self.estilos_inline = []
        self.css = []
        self.scripts = []
        self.imagens = []
        self.links = []
        self._em_style = False
        self._em_script = False
        self._buffer = []

    def handle_starttag(self, tag, attrs):
        a = dict(attrs)
        if tag not in VAZIAS:
            self.pilha.append(tag)
        if tag == "script":
            if a.get("src"):
                self.dependencias.append(a["src"])
            self._em_script = True
            self._buffer = []
        elif tag == "link" and a.get("href"):
            self.dependencias.append(a["href"])
        elif tag == "style":
            self._em_style = True
            self._buffer = []
        if tag == "sc-if":
            self.condicionais.append(a.get("value", "?"))
        if tag == "sc-for":
            self.repeticoes.append(
                f"{a.get('list', '?')} como `{a.get('as', '?')}` "
                f"(exemplo: {a.get('hint-placeholder-count', '?')} itens)"
            )
        if tag == "img" and a.get("src"):
            self.imagens.append(a["src"])
        if tag == "a" and a.get("href") and not a["href"].startswith(("#", "{{")):
            self.links.append(a["href"])
        if a.get("data-screen-label"):
            self.secoes.append(a["data-screen-label"])
        for nome, valor in attrs:
            if valor is None:
                continue
            if nome.startswith("on"):
                self.eventos[f"{nome} ({tag})"] += 1
            if nome in ATRIBUTOS_DE_TEXTO and valor.strip() and tag not in ("sc-if", "sc-for"):
                self.atributos.append(f"{tag}[{nome}]: {valor.strip()}")
            if nome == "style":
                self.estilos_inline.append(valor)

    def handle_endtag(self, tag):
        if tag == "style" and self._em_style:
            self.css.append("".join(self._buffer))
            self._em_style = False
        if tag == "script" and self._em_script:
            corpo = "".join(self._buffer).strip()
            if corpo:
                self.scripts.append(corpo)
            self._em_script = False
        if self.pilha and self.pilha[-1] == tag:
            self.pilha.pop()

    def handle_data(self, data):
        if self._em_style or self._em_script:
            self._buffer.append(data)
            return
        texto = " ".join(data.split())
        if texto:
            onde = ">".join(self.pilha[-3:])
            self.textos.append((onde, texto))


def strings_do_js(js):
    """Devolve os literais de string do JS (aspas simples, duplas e template), pulando
    comentários e regex literal, que confundem uma busca simples por aspas."""
    saida, i, n, anterior = [], 0, len(js), ""
    while i < n:
        c = js[i]
        if js.startswith("//", i):
            i = js.find("\n", i)
            i = n if i < 0 else i
            continue
        if js.startswith("/*", i):
            i = js.find("*/", i + 2)
            i = n if i < 0 else i + 2
            continue
        if c == "/" and (anterior == "" or anterior in "(,=:[!&|?{};+-*%<>~^" or js[max(0, i - 6):i].rstrip().endswith("return")):
            i += 1
            classe = False
            while i < n and (js[i] != "/" or classe) and js[i] != "\n":
                if js[i] == "\\":
                    i += 1
                elif js[i] == "[":
                    classe = True
                elif js[i] == "]":
                    classe = False
                i += 1
            i += 1
            anterior = "a"
            continue
        if c in "'\"`":
            j, pedaco, profundidade = i + 1, [], 0
            while j < n:
                d = js[j]
                if d == "\\":
                    pedaco.append(js[j:j + 2])
                    j += 2
                    continue
                if c == "`" and js.startswith("${", j):
                    profundidade += 1
                    pedaco.append(" ")
                    j += 2
                    continue
                if c == "`" and profundidade and d == "}":
                    profundidade -= 1
                    j += 1
                    continue
                if profundidade:
                    j += 1
                    continue
                if d == c or (d == "\n" and c != "`"):
                    break
                pedaco.append(d)
                j += 1
            saida.append("".join(pedaco))
            i = j + 1
            anterior = "a"
            continue
        if not c.isspace():
            anterior = c if not (c.isalnum() or c in "_$)]") else "a"
        i += 1
    return saida


def secao(titulo, linhas):
    print(f"\n## {titulo} ({len(linhas)})\n")
    if not linhas:
        print("(nenhum)")
    for linha in linhas:
        print(f"- [ ] {linha}")


def main():
    if len(sys.argv) != 2:
        sys.exit('uso: inventario_dc.py "<arquivo>.dc.html"')
    caminho = sys.argv[1]
    fonte = open(caminho, encoding="utf-8").read()
    leitor = Leitor()
    leitor.feed(fonte)

    print(f"# Inventário de `{os.path.basename(caminho)}`")
    print("\nCada linha é um item da conferência ✓/✗. Nada aqui é opcional: o que está no desenho entra.")
    final = fonte.rstrip()[-200:].lower()
    if "</x-dc>" not in final and "</html>" not in final:
        print(f"\n> ⚠ O arquivo não termina em </x-dc> nem </html> ({len(fonte.encode())} bytes): "
              "pode estar TRUNCADO. Peça o desenho inteiro antes de implementar.")

    pasta = os.path.dirname(caminho)
    raiz = pasta
    while os.path.basename(raiz) not in ("design", "") and os.path.dirname(raiz) != raiz:
        raiz = os.path.dirname(raiz)
    deps = []
    for dep in leitor.dependencias:
        if re.match(r"https?://", dep) or os.path.exists(os.path.join(pasta, dep)):
            deps.append(dep)
            continue
        nome = os.path.basename(dep)
        achados = sorted(
            os.path.relpath(os.path.join(d, nome), raiz) for d, _, arqs in os.walk(raiz) if nome in arqs
        )
        if achados:
            deps.append(f"{dep}  ⚠ não está na pasta do desenho; há cópia(s) em: {', '.join(achados[:4])} (confirmar se é a mesma versão)")
        else:
            deps.append(f"{dep}  ⚠ NÃO ENCONTRADO: comportamento (tema, idioma, dicas...) pode estar faltando; pedir ao dono")
    secao("Dependências (helmet, scripts e folhas)", deps)

    regras = []
    for bloco in leitor.css:
        for regra in re.findall(r"[^{}]+\{[^{}]*\}|@media[^{]+\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}", bloco):
            regras.append("`" + " ".join(regra.split()) + "`")
    secao("Regras do <style> (inclui @media, :hover, :focus)", regras)

    secao("Seções (data-screen-label)", leitor.secoes)
    secao("Condicionais sc-if: cada uma é um estado que pode estar escondido no print", leitor.condicionais)
    secao("Repetições sc-for", leitor.repeticoes)
    secao("Eventos: cada um é um comportamento", [f"{k} ×{v}" for k, v in leitor.eventos.most_common()])

    cores = collections.Counter()
    medidas = collections.Counter()
    for estilo in leitor.estilos_inline + leitor.css + leitor.scripts:
        cores.update(c.lower() for c in COR.findall(estilo))
        medidas.update(f"{p}: {v.strip()}" for p, v in MEDIDA.findall(estilo))
    secao("Cores (inline, <style> e JS), por frequência", [f"`{c}` ×{n}" for c, n in cores.most_common()])
    secao("Medidas e propriedades (inline, <style> e JS), por frequência", [f"`{m}` ×{n}" for m, n in medidas.most_common()])

    secao("Textos visíveis na marcação", [f"{t}  _({onde})_" for onde, t in leitor.textos if "{{" not in t])
    secao("Textos que vêm do estado ({{ }})", sorted({t for _, t in leitor.textos if "{{" in t}))
    secao("Textos de atributo (title, placeholder, aria-label...)", leitor.atributos)

    js = "\n".join(leitor.scripts)

    def existe(ref):
        alvo = ref.split("#")[0].split("?")[0]
        return re.match(r"(https?:|data:|mailto:)", ref) or not alvo or os.path.exists(os.path.join(pasta, alvo))

    refs_js = re.findall(r"['\"`](\.{0,2}/?[^'\"`\s]+\.(?:png|jpe?g|svg|webp|gif|dc\.html)(?:#[^'\"`]*)?)['\"`]", js)
    secao(
        "Imagens e links para outros desenhos (marcação e JS)",
        [f"{r}{'' if existe(r) else '  ⚠ NÃO ESTÁ NA PASTA'}" for r in dict.fromkeys(leitor.imagens + leitor.links + refs_js)],
    )

    estado = [js[m.start():m.start() + 400] for m in re.finditer(r"\bstate\s*=", js)]
    secao(
        "Estado inicial do componente (trecho; leia o original)",
        ["`" + " ".join(e.split()) + "`" for e in estado]
        or ["⚠ não encontrei `state =` pelo padrão: procure o estado inicial à mão (pode ser função, construtor ou useState). Ausência aqui NÃO quer dizer que não há estado."],
    )
    textos_js = []
    for t in strings_do_js(js):
        if "<" in t or ">" in t:
            t = " ".join(re.sub(r"<[^>]*>|^[^<]*>|<[^>]*$", " ", t).split())
        palavra = re.fullmatch(r"[A-ZÀ-Ú][a-zà-úA-ZÀ-Ú.]{3,}", t) and not re.search(r"[a-z][A-Z]", t)
        if palavra:
            textos_js.append(t)
            continue
        codigo = re.search(r"=>|\)\);|\bconst\b|\blet\b|\breturn\b|[{}]|\|\||&&|\bthis\.", t)
        if re.search(r"[A-Za-zÀ-ú]{3,}\s+[A-Za-zÀ-ú]", t) and not codigo and not re.match(r"^[\w.-]+\.(js|html|png|svg)$", t):
            textos_js.append(t)
    secao(
        "Textos que só existem no JavaScript (mensagens, toasts, vazios, tooltips, rótulos calculados)",
        sorted(set(textos_js)),
    )

    travessoes = sorted({t for _, t in leitor.textos if re.search("[—–]", t)} | {t for t in textos_js if re.search("[—–]", t)})
    if travessoes:
        secao("⚠ Textos com travessão (a regra do projeto proíbe: reescrever e avisar)", travessoes)


if __name__ == "__main__":
    main()
