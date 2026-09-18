<?php

declare(strict_types=1);

namespace App\Shared\Armazenamento;

use Psr\Log\LoggerInterface;

/**
 * Remove arquivos quando o destino do banco JÁ está decidido (INV-6, E2.5).
 *
 * Dois usos, e só dois:
 *
 *  - **depois do COMMIT confirmado**, os arquivos cujas linhas foram apagadas;
 *  - **arquivo novo que nenhuma linha pode referenciar**: a transação comprovadamente não
 *    confirmou — decisão que é de `App\Shared\Doctrine\Transacao\TransacaoComArquivoNovo`, e não
 *    de quem chama (um COMMIT de resultado incerto preserva o arquivo) — ou a falha aconteceu
 *    antes de a transação começar (o `catch` do download do Drive, para a guarda de tamanho).
 *
 * Nada impede, tecnicamente, chamar isto antes do `flush` ou num `catch` que envolva o COMMIT —
 * e as duas coisas reintroduzem os defeitos que a E2.5 fechou. A guarda é a revisão e os testes
 * de cada ponto, que injetam a falha do banco e conferem que o arquivo ficou.
 *
 * ## A ordem é obrigação de quem chama
 *
 * 1. montar as chaves **antes** de remover as entidades (depois do `flush` a dona some do
 *    UnitOfWork, e coleção preguiçosa de entidade removida é terreno incerto);
 * 2. confirmar o banco (`flush` simples, ou o fim do `wrapInTransaction`);
 * 3. só então entregar as chaves aqui.
 *
 * Chamar isto antes do COMMIT recria o defeito que a E2.5 fechou: arquivo apagado, banco
 * desfeito, registro válido apontando para o vazio.
 *
 * ## Política de falha — o banco é autoritativo
 *
 * A alteração do banco já está decidida quando isto roda. Uma falha física (disco ilegível,
 * permissão, chave que o storage recusa) **não** desfaz nada e **não** vira erro para o usuário:
 * cada chave é tentada isoladamente, a falha vira `logger->error` com a chave e o contexto, e as
 * demais seguem. O arquivo que ficou é órfão recuperável — o estado que INV-6 aceita.
 *
 * `existe()` antes de `excluir()` serve para duas coisas: contar o que foi de fato removido (a
 * purga reporta isso) e provar a cadeia de diretórios, que é o que faz "não consegui ver" virar
 * registro em vez de silêncio.
 */
final readonly class RemocaoAposTransacao
{
    public function __construct(
        private ArmazenamentoDeArquivos $armazenamento,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param iterable<ChaveDeArquivo|\Closure(): ChaveDeArquivo> $chaves A forma adiada é para
     *        quem só pode montar a chave depois do COMMIT (o nome anterior da foto de perfil): uma
     *        chave recusada ali também vira registro, e não 500 com o banco já confirmado.
     * @param string $contexto Quem removeu e por quê — vai para o log, que é o único rastro do órfão.
     */
    public function remover(iterable $chaves, string $contexto): ResultadoDaRemocao
    {
        $removidos    = 0;
        $naoRemovidas = [];

        foreach ($chaves as $item) {
            $chave = null;

            try {
                $chave = $item instanceof \Closure ? $item() : $item;

                if (!$this->armazenamento->existe($chave)) {
                    continue;
                }

                $this->armazenamento->excluir($chave);
                $removidos++;
            } catch (\Throwable $e) {
                $descricao      = $chave instanceof ChaveDeArquivo ? $chave->comoTexto() : '(chave não montada)';
                $naoRemovidas[] = $descricao;

                $this->registrar(
                    'error',
                    'Arquivo não removido depois da transação; ficou órfão no storage e precisa de limpeza manual.',
                    [
                        'contexto' => $contexto,
                        'chave'    => $descricao,
                        'erro'     => $e->getMessage(),
                        'classe'   => $e::class,
                    ],
                );
            }
        }

        return new ResultadoDaRemocao($removidos, $naoRemovidas);
    }

    /**
     * Registra que os arquivos ficaram DE PROPÓSITO — o destino da transação não autoriza apagar.
     *
     * Não toca no storage. Existe para que o arquivo preservado tenha rastro: se a transação na
     * verdade não confirmou, ele é um órfão que alguém vai precisar achar.
     *
     * @param iterable<ChaveDeArquivo> $chaves
     */
    public function preservar(iterable $chaves, string $contexto, string $motivo): void
    {
        foreach ($chaves as $chave) {
            $this->registrar(
                'warning',
                'Arquivo preservado: o destino da transação não autoriza removê-lo.',
                ['contexto' => $contexto, 'chave' => $chave->comoTexto(), 'motivo' => $motivo],
            );
        }
    }

    /**
     * O registro nunca muda o desfecho: um logger que lança (handler sem destino gravável) não
     * pode transformar "banco confirmado, arquivo ficou" em exceção depois do COMMIT.
     *
     * @param array<string, mixed> $contexto
     */
    private function registrar(string $nivel, string $mensagem, array $contexto): void
    {
        try {
            $this->logger->log($nivel, $mensagem, $contexto);
        } catch (\Throwable) {
            // Sem onde registrar: o resultado devolvido (naoRemovidas) continua sendo o rastro.
        }
    }
}
