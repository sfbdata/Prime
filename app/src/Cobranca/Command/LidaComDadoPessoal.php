<?php

declare(strict_types=1);

namespace App\Cobranca\Command;

/**
 * Marca um comando de console que **lê, grava ou imprime dado pessoal de terceiro** — nome, CPF/CNPJ,
 * e-mail, telefone, endereço, ou a dupla unidade+boleto que identifica o devedor.
 *
 * 🔴 Quem implementa esta interface **é obrigado a consultar** {@see \App\Cobranca\Service\Espelho\GuardaDeLogComPii}
 * antes de qualquer leitura (INV-Q10). Com `APP_DEBUG=1` o middleware do Doctrine imprime os
 * parâmetros de cada consulta, e esses parâmetros são o dado pessoal por extenso — numa saída que o
 * dono cola em chat.
 *
 * ## Por que é declaração, e não adivinhação
 *
 * Duas versões anteriores desta trava falharam:
 *
 * 1. **lista de caminhos no teste** — comando novo que esquecesse a guarda passava;
 * 2. **heurística por marcas de PII no código** — era **circular**. Apagando a guarda dos quatro
 *    comandos do espelho e da reconciliação sobravam ZERO marcas, porque a única que eles tinham era
 *    a string da própria opção `--aceito-log-com-pii`. *O teste ficava verde justamente quando a
 *    proteção era removida.*
 *
 * > *"Se a proteção é 'os comandos que gravam dado pessoal', que seja essa a régua, e não uma
 * > lista."* — dono, 13/08/2026.
 *
 * Hoje **todo** `*Command.php` de `src/Cobranca/Command/` implementa esta interface **ou**
 * {@see NaoLidaComDadoPessoal}, e quem não declarar nenhuma **quebra a suíte**
 * (`ComandosComPiiPassamPelaGuardaTest`). Comando novo não nasce fora da régua porque não nasce sem
 * decidir — e a checagem é por `ReflectionClass::implementsInterface()`, não por texto.
 *
 */
interface LidaComDadoPessoal
{
}
