<?php

declare(strict_types=1);

namespace App\Pasta\UseCase;

use App\Entity\Auth\User;
use App\Pasta\Entity\Pasta;
use App\Entity\Tenant\Tenant;
use App\Pasta\Service\NumeracaoDePastaInterface;
use App\Shared\Service\ArquivoStorageInterface;
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
 */
final class ExcluirPastaUseCase
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ArquivoStorageInterface $storage,
        private readonly string $uploadsDir,
        private readonly NumeracaoDePastaInterface $numeracao,
    ) {}

    public function executar(Pasta $pasta, User $autor, Tenant $tenant): ResultadoExclusaoPasta
    {
        if ($pasta->getTenant() !== $tenant) {
            throw new AccessDeniedException('Pasta não pertence ao tenant do usuário.');
        }

        if ($pasta->estaExcluida()) {
            throw new \LogicException('Esta pasta já está excluída.');
        }

        return $this->em->wrapInTransaction(function () use ($pasta, $autor, $tenant): ResultadoExclusaoPasta {
            $this->numeracao->travar($tenant);

            if ($this->numeracao->existeNumeroMaiorQue($tenant, $pasta->getNup())) {
                // Lápide. Os arquivos ficam de propósito: sem eles a pasta riscada abriria vazia e
                // não serviria para consultar o que foi feito no caso.
                $pasta->marcarExcluida($autor, new \DateTimeImmutable());
                $this->em->flush();

                return ResultadoExclusaoPasta::Lapide;
            }

            foreach ($pasta->getDocumentos() as $doc) {
                $caminho = $this->storage->caminho($this->uploadsDir, $doc->getCaminhoArquivo());
                if ($this->storage->existe($caminho)) {
                    $this->storage->excluir($caminho);
                }
            }

            $this->em->remove($pasta);
            $this->em->flush();

            return ResultadoExclusaoPasta::Removida;
        });
    }
}
