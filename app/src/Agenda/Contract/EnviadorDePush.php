<?php

declare(strict_types=1);

namespace App\Agenda\Contract;

use App\Agenda\Entity\InscricaoDePush;

/** Envia avisos por web push. Atrás de interface para os testes não falarem com o serviço do navegador. */
interface EnviadorDePush
{
    /** Falso quando o servidor não tem as chaves VAPID: o push fica desligado, sem erro. */
    public function ligado(): bool;

    public function chavePublica(): ?string;

    /**
     * @param list<array{inscricao: InscricaoDePush, conteudo: array<string, string>}> $envios
     *
     * @return list<InscricaoDePush> inscrições que o navegador já cancelou (devem ser apagadas)
     */
    public function enviar(array $envios): array;
}
