<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Entity\Tenant\Tenant;
use App\Pasta\Armazenamento\ChavesDePasta;
use App\Pasta\Entity\Pasta;
use App\Pasta\Repository\PastaDocumentoRepository;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Shared\Armazenamento\ChaveDeArquivo;
use App\Shared\Armazenamento\RemocaoAposTransacao;
use App\Shared\Doctrine\Filter\AcessoALixeira;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Exclui a pasta — de dois jeitos, conforme a posição dela na sequência do escritório.
 *
 * **Tem pasta com número maior → lápide.** A linha fica: `excluida_em`/`excluida_por` preenchidos,
 * situação para ARQUIVADA, arquivos preservados. A pasta continua na lista do Expediente, riscada,
 * e fica somente-leitura (a trava é do `PastaSomenteLeituraListener`).
 *
 * **É a última da sequência → apaga de verdade**, como sempre foi, arquivos inclusive.
 *
 * O porquê da divisão: o número da pasta é MAX(prefixo)+1 (ver `GerarNumeroDePasta`), então apagar
 * a linha de uma pasta do MEIO deixava o número órfão para sempre e sem explicação nenhuma na tela
 * — em produção, 185 buracos entre 1 e 1240. Já apagar a ÚLTIMA devolve o número, e essa é a
 * escolha do dono para o caso comum de criar por engano e apagar na hora.
 *
 * A leitura de "sou a última?" acontece com a sequência TRAVADA e dentro da mesma transação da
 * gravação. Sem isso a decisão pode nascer errada em silêncio: entre ler e gravar, alguém criando a
 * próxima pasta faria esta virar do meio — e ela teria sido apagada de verdade como se fosse a
 * última, criando exatamente o buraco que a lápide existe para impedir.
 *
 * **A lixeira da aba Documentos vai junto (D7).** Apagar de verdade roda dentro de
 * `AcessoALixeira::comLixeiraVisivel()`: os documentos e subpastas que o usuário tinha mandado
 * para a lixeira são lidos (o `LixeiraFilter` os esconderia), removidos explicitamente — a FK
 * `pasta_documento.pasta_id` não tem ON DELETE CASCADE, e um documento na lixeira que o cascade
 * do ORM não enxergasse quebraria o DELETE da pasta — e os arquivos deles entram na lista que sai
 * depois do COMMIT. Na lápide nada disso acontece: a lixeira fica como está.
 *
 * **Arquivos só saem depois do COMMIT (E2.5, INV-6).** As chaves são montadas dentro da
 * transação, antes do `remove`; os arquivos são removidos quando o `wrapInTransaction` já
 * retornou. Falha física depois do COMMIT vira registro no log e órfão recuperável.
 */
final class ExcluirPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly RemocaoAposTransacao $remocao,
        private readonly NumeracaoDePastaInterface $numeracao,
        private readonly PastaDocumentoRepository $documentos,
        private readonly AcessoALixeira $lixeira,
    ) {}

    public function executar(Pasta $pasta, User $autor, Tenant $tenant): ResultadoExclusaoPasta
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        if ($pasta->estaExcluida()) {
            throw new \LogicException('Esta pasta já está excluída.');
        }

        /** @var list<ChaveDeArquivo> $chaves */
        $chaves = [];

        $resultado = $this->em->wrapInTransaction(function () use ($pasta, $autor, $tenant, &$chaves): ResultadoExclusaoPasta {
            $this->numeracao->travar($tenant);

            if ($this->numeracao->existeNumeroMaiorQue($tenant, $pasta->getNup())) {
                // Lápide. Os arquivos ficam de propósito: sem eles a pasta riscada abriria vazia e
                // não serviria para consultar o que foi feito no caso.
                $pasta->marcarExcluida($autor, new \DateTimeImmutable());
                $this->em->flush();

                return ResultadoExclusaoPasta::Lapide;
            }

            // Remoção real: lixeira visível para a leitura, os removes E o flush — um proxy de
            // seção excluída iniciado com o filtro ligado falharia, e o cascade do ORM só leva o
            // que as coleções enxergam.
            return $this->lixeira->comLixeiraVisivel(function () use ($pasta, $tenant, &$chaves): ResultadoExclusaoPasta {
                foreach ($this->documentos->listarParaRemocaoDaPasta($pasta, $tenant) as $doc) {
                    $chaves[] = ChavesDePasta::documento($doc);
                    $this->em->remove($doc);
                }

                $this->em->remove($pasta);
                $this->em->flush();

                return ResultadoExclusaoPasta::Removida;
            });
        });

        // Fora do closure: o COMMIT do wrapInTransaction acontece depois que ele retorna.
        $this->remocao->remover($chaves, 'ExcluirPastaUseCase');

        return $resultado;
    }
}
