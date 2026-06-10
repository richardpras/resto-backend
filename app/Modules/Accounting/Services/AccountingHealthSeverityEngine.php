<?php

namespace App\Modules\Accounting\Services;

final class AccountingHealthSeverityEngine
{
  public const SEVERITY_HEALTHY = 'healthy';

  public const SEVERITY_WARNING = 'warning';

  public const SEVERITY_HIGH = 'high';

  public const SEVERITY_CRITICAL = 'critical';

  /** @var array<string, int> */
  private const SEVERITY_RANK = [
      self::SEVERITY_HEALTHY => 0,
      self::SEVERITY_WARNING => 1,
      self::SEVERITY_HIGH => 2,
      self::SEVERITY_CRITICAL => 3,
  ];

  public function postingFailuresSeverity(int $count): string
  {
      if ($count <= 0) {
          return self::SEVERITY_HEALTHY;
      }
      if ($count <= 5) {
          return self::SEVERITY_WARNING;
      }
      if ($count <= 20) {
          return self::SEVERITY_HIGH;
      }

      return self::SEVERITY_CRITICAL;
  }

  public function giftCardVarianceSeverity(float $absVariance): string
  {
      if ($absVariance <= 0.01) {
          return self::SEVERITY_HEALTHY;
      }
      if ($absVariance <= 1) {
          return self::SEVERITY_WARNING;
      }

      return self::SEVERITY_CRITICAL;
  }

  public function inventoryVarianceSeverity(float $absDifference): string
  {
      if ($absDifference <= 0.01) {
          return self::SEVERITY_HEALTHY;
      }
      if ($absDifference <= 5) {
          return self::SEVERITY_WARNING;
      }

      return self::SEVERITY_CRITICAL;
  }

  public function payrollVarianceSeverity(float $absDifference): string
  {
      if ($absDifference <= 0.01) {
          return self::SEVERITY_HEALTHY;
      }

      return self::SEVERITY_WARNING;
  }

  public function procurementVarianceSeverity(float $absDifference): string
  {
      if ($absDifference <= 0.01) {
          return self::SEVERITY_HEALTHY;
      }

      return self::SEVERITY_WARNING;
  }

  /**
   * @param  list<string>  $severities
   */
  public function aggregateSeverity(array $severities): string
  {
      $worst = self::SEVERITY_HEALTHY;
      foreach ($severities as $severity) {
          if ((self::SEVERITY_RANK[$severity] ?? 0) > (self::SEVERITY_RANK[$worst] ?? 0)) {
              $worst = $severity;
          }
      }

      return $worst;
  }

  public function isWorsening(string $previous, string $current): bool
  {
      return (self::SEVERITY_RANK[$current] ?? 0) > (self::SEVERITY_RANK[$previous] ?? 0);
  }

  public function severityRank(string $severity): int
  {
      return self::SEVERITY_RANK[$severity] ?? 0;
  }
}
