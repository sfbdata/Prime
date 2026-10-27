# Peticionar F10: aceitar qualquer formato (spec de riscos)

**Risco:** ALTO para a decisão (arquivo de usuário servido de volta, superfície de malware) · **Frente:** `peticionar-formatos-spec-27` · **Data:** 27/10/2026
**Estado:** só spec. Nenhum código. A decisão de aceitar ou não é do dono (seção "Decisões").
Origem: item F10 de `docs/specs/peticionar-desenho-07.md` ("Aceitar qualquer formato").

## O que o desenho pede

O anexo do Peticionar aceitaria qualquer arquivo, sem lista de tipos. Hoje a dica do anexo lista o que o servidor aceita.

## O que existe hoje (medido no código)

- `UploadPecaUseCase`: **lista fechada** de MIME (`MIME_LIMITS`, ~35 tipos) com teto por tipo: imagem 3 MB, PDF/Office/ODF/e-mail 10 MB, texto/CSV/XML 5 MB, áudio 10 MB (opus 50), vídeo 50 MB, zip 50 MB.
- O tipo vem do conteúdo (`finfo`), não da extensão ou do `Content-Type` do cliente. Zip com extensão de documento (`.odt`, `.pptx`...) só passa se o pacote declarar o tipo por dentro (`PACOTES_POR_EXTENSAO`).
- Arquivo guardado por chave (skill `armazenamento-de-arquivos`), nunca por caminho; download pela rota da pasta com `Content-Disposition: attachment` (`PastaDocumentoController`).
- Prévia de DOCX/XLSX (F9) convertida no servidor, HTML sob `Content-Security-Policy: sandbox; default-src 'none'`, 10 MB, defesa contra zip bomb.
- **Não há antivírus** no repositório (nem ClamAV, nem varredura em fila).

## Riscos de aceitar qualquer formato

| # | Risco | Como acontece | Gravidade |
|---|---|---|---|
| 1 | **Malware** (executável, macro de Office, PDF com ação, script) | um usuário do escritório anexa e outro baixa e abre | alta: ataque a cliente do SaaS por dentro |
| 2 | **Tipos perigosos servidos de volta no domínio**: `.html`, `.svg`, `.xhtml`, `.js`, `.swf` | se abrirem inline, executam script na origem do BlueJus (XSS armazenado, roubo de sessão) | crítica |
| 3 | **Executáveis** (`.exe`, `.msi`, `.bat`, `.ps1`, `.sh`, `.jar`, `.apk`, `.scr`, `.lnk`, `.dll`) | usuário baixa e executa | alta |
| 4 | **Tamanho** | sem teto por tipo, o disco/R2 e o tempo de upload viram vetor de abuso (custo e DoS); `post_max_size`/`upload_max_filesize` do PHP e do nginx são o teto real | média |
| 5 | **Bombas de descompressão** (zip, docx, xlsx, gzip, 7z) | arquivo pequeno que expande para GBs na prévia ou na varredura | alta para a prévia (já tratada só no OOXML da F9) |
| 6 | **Tipo mentido**: extensão `.pdf` com conteúdo executável; polyglot (PDF + HTML) | validar por extensão ou pelo `Content-Type` do cliente | alta |
| 7 | **Download**: sem `Content-Disposition: attachment` e `X-Content-Type-Options: nosniff` o navegador "adivinha" e exibe inline | tipo desconhecido vira HTML na origem | crítica |
| 8 | **Nome do arquivo**: `../`, caracteres de controle, nome longo, RTL override (`fdp.exe` exibido como `exe.pdf`), cabeçalho injetado no download | nome vindo do cliente | média |
| 9 | **Vazamento entre escritórios** | arquivo servido sem checar tenant e permissão da pasta | alta (já coberta pela rota atual; não pode regredir) |
| 10 | **LGPD / retenção** | arquivo de tipo que o sistema não sabe ler (sem prévia, sem texto) fica fora da busca e da exclusão por pasta se alguém esquecer o vínculo | média |
| 11 | **Propagação para terceiros**: o Push compartilhado e o e-mail podem levar o arquivo a cliente final | malware sai do BlueJus com a marca do escritório | alta |

## Controles propostos (se o dono aprovar)

Em camadas; nenhuma sozinha basta.

1. **Lista de bloqueio dura, que vence qualquer permissão**: executáveis e scripts (item 3) e `.html/.htm/.xhtml/.svg/.swf/.js/.mjs/.hta/.jar/.apk/.msi/.lnk`. Bloqueio pelo **tipo detectado (`finfo`) E pela extensão**: basta um dos dois para recusar.
2. **Tipo pelo conteúdo, nunca pelo cliente**: gravar o MIME detectado; desconhecido vira `application/octet-stream`, entregue **só como download**.
3. **Download sempre `attachment`** + `X-Content-Type-Options: nosniff` + `Content-Security-Policy: sandbox` nas respostas de arquivo; nome em `filename*=UTF-8''` já saneado (remove controle, bidi e barras).
4. **Teto de tamanho por classe**: manter os atuais para os tipos conhecidos; tipo "outro" com teto único baixo (sugestão: 10 MB), nunca ilimitado. Alinhar `post_max_size`, `upload_max_filesize` e `client_max_body_size` do nginx ao maior teto.
5. **Antivírus**: varredura com ClamAV (container próprio, `clamd`) **antes** de o arquivo ficar disponível. Estado novo do documento: `pendente_varredura` → `limpo` | `infectado` | `falha_na_varredura`. Só `limpo` baixa ou aparece para outros usuários. `infectado`: quarentena (nunca apaga na hora, para auditoria), aviso ao autor e ao admin. Falha da varredura: **fecha** (não libera). Custo: serviço novo, RAM (~1 GB), atualização diária de assinaturas, fila.
6. **Zip e afins**: abrir só com limites (número de entradas, tamanho expandido, razão de compressão, profundidade, sem `..` nos nomes), como já feito para OOXML na F9; arquivo aninhado recusado ou varrido.
7. **Auditoria**: quem enviou, quando, MIME detectado, hash SHA-256, resultado da varredura. O hash permite bloquear arquivo conhecido e achar quem recebeu.
8. **Compartilhamento externo**: Push compartilhado e e-mail só levam arquivo `limpo` e de classe conhecida; "outro" não sai do sistema.
9. **Interface**: a dica do anexo diz o que passa e o que não passa; recusa com motivo claro ("este tipo é bloqueado por segurança").

## Alternativas (da mais segura à mais aberta)

- **A. Manter a lista fechada e ampliá-la** pontualmente (ex.: `.dwg`, `.mp3`...) conforme pedidos. Risco quase igual ao de hoje. Recomendada se não houver antivírus.
- **B. Lista aberta com bloqueio dura + tipo "outro" só download + teto**, sem antivírus. Reduz XSS (itens 2, 7) mas **não** malware (1, 11).
- **C. B + antivírus (ClamAV) com quarentena.** Cobre os riscos de 1 a 11. Exige serviço novo e fila.

**Recomendação:** A agora; C se o dono quiser "qualquer formato". B sozinha não deve ir para produção: aceita arquivo que ninguém varre e que um colega vai abrir.

## Decisões que exigem o dono

1. Quer "qualquer formato" mesmo, ou basta ampliar a lista (A)?
2. Se for C: aceita o custo do ClamAV (container, RAM, atualização de assinaturas) e o atraso de alguns segundos até o arquivo ficar disponível?
3. Teto do tipo "outro" (sugestão 10 MB) e se vídeo/áudio mantêm os 50 MB.
4. Arquivo infectado: quarentena visível ao admin do escritório ou só ao Master? Por quanto tempo (sugestão: 30 dias, como a lixeira)?
5. Esses arquivos podem sair pelo Push compartilhado e por e-mail? (Sugestão: não, só classes conhecidas e limpas.)
6. Quem recebe o aviso de arquivo bloqueado ou infectado.

## Se aprovada (fatias, para depois)

| Fatia | Conteúdo |
|---|---|
| X1 | Lista de bloqueio dura + tipo "outro" só download + teto + cabeçalhos de download (sem antivírus) |
| X2 | Serviço ClamAV, estado de varredura no documento (migration), fila e quarentena |
| X3 | Auditoria (hash, MIME, resultado) e bloqueio por hash |
| X4 | Limites de arquivo compactado (zip/7z/gz) |

Testes mínimos de cada fatia: extensão mentirosa, polyglot, nome com bidi e `../`, zip bomb, arquivo no limite e 1 byte acima, download sem permissão e de outro tenant, `attachment` + `nosniff` em toda resposta de arquivo, EICAR (arquivo de teste de antivírus) em quarentena.
