<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// ─── ROLE & FILTER PARAMS ─────────────────────────────────────────────────
$role       = isset($_GET['role'])       ? $_GET['role']       : 'ceo';
$agent_id   = isset($_GET['agent_id'])   ? (int)$_GET['agent_id']   : 1;
$manager_id = isset($_GET['manager_id']) ? (int)$_GET['manager_id'] : 10;
$year       = isset($_GET['year'])       ? $_GET['year']       : 'All';
$quarter    = isset($_GET['quarter'])    ? $_GET['quarter']    : 'All';
$month      = isset($_GET['month'])      ? $_GET['month']      : 'All';
$deal_type  = isset($_GET['deal_type'])  ? $_GET['deal_type']  : 'All';
$year1      = isset($_GET['year1'])      ? (int)$_GET['year1'] : 2024;
$year2      = isset($_GET['year2'])      ? (int)$_GET['year2'] : 2025;

// ─── FILTER META ──────────────────────────────────────────────────────────
$filters = array(
    "years"      => array(2023, 2024, 2025, 2026),
    "quarters"   => array("Q1", "Q2", "Q3", "Q4"),
    "months"     => array("Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec"),
    "deal_types" => array("All", "Offplan", "Secondary", "Rental"),
);

// ─── AGENT ROSTER ─────────────────────────────────────────────────────────
$agents = array(
    1  => array("id" => 1,  "name" => "Ali Arikat",      "manager_id" => 10, "designation" => "Private Office Advisor", "joined" => "2018-11-25", "employee_no" => "40653"),
    2  => array("id" => 2,  "name" => "Sara Mitchell",   "manager_id" => 10, "designation" => "Sales Associate",        "joined" => "2020-03-12", "employee_no" => "40701"),
    3  => array("id" => 3,  "name" => "Rami Hassan",     "manager_id" => 10, "designation" => "Senior Consultant",      "joined" => "2019-07-01", "employee_no" => "40680"),
    4  => array("id" => 4,  "name" => "Nadia Al-Farsi",  "manager_id" => 11, "designation" => "Sales Associate",        "joined" => "2021-01-15", "employee_no" => "40720"),
    5  => array("id" => 5,  "name" => "James Thornton",  "manager_id" => 11, "designation" => "Senior Consultant",      "joined" => "2017-09-20", "employee_no" => "40610"),
    6  => array("id" => 6,  "name" => "Priya Sharma",    "manager_id" => 11, "designation" => "Sales Associate",        "joined" => "2022-06-01", "employee_no" => "40745"),
    7  => array("id" => 7,  "name" => "Omar Khalil",     "manager_id" => 12, "designation" => "Senior Consultant",      "joined" => "2019-02-14", "employee_no" => "40665"),
    8  => array("id" => 8,  "name" => "Elena Petrova",   "manager_id" => 12, "designation" => "Private Office Advisor", "joined" => "2018-04-03", "employee_no" => "40641"),
    9  => array("id" => 9,  "name" => "David Lee",       "manager_id" => 12, "designation" => "Sales Associate",        "joined" => "2023-01-10", "employee_no" => "40780"),
    10 => array("id" => 10, "name" => "Aldo de Jager",   "manager_id" => 20, "designation" => "Team Leader",            "joined" => "2016-05-11", "employee_no" => "40590"),
    11 => array("id" => 11, "name" => "Mohamed El Sayed", "manager_id" => 20, "designation" => "Team Leader",            "joined" => "2015-08-22", "employee_no" => "40570"),
    12 => array("id" => 12, "name" => "Sarah Williams",  "manager_id" => 20, "designation" => "Team Leader",            "joined" => "2017-11-30", "employee_no" => "40605"),
    20 => array("id" => 20, "name" => "CEO User",        "manager_id" => 0,  "designation" => "General Manager",        "joined" => "2012-01-01", "employee_no" => "40001"),
);

// ─── TEAM ROSTER ─────────────────────────────────────────────────────────
$teams = array(
    1  => array("id" => 1,  "name" => "Sales Team 1",   "manager_id" => 2),
    2  => array("id" => 2,  "name" => "Sales Team 2",   "manager_id" => 3),
    3  => array("id" => 3,  "name" => "Sales Team 3",   "manager_id" => 5),
    4  => array("id" => 4,  "name" => "Sales Team 4",   "manager_id" => 6),
    5  => array("id" => 5,  "name" => "Sales Team 5",   "manager_id" => 7),
    6  => array("id" => 6,  "name" => "Private Office", "manager_id" => 11),
);

// ─── MONTH DATA GENERATOR ────────────────────────────────────────────────
function buildMonthData($base_sales, $base_commission, $base_deals, $seed)
{
    $months = array("Jan", "Feb", "Mar", "Apr", "May", "Jun", "Jul", "Aug", "Sep", "Oct", "Nov", "Dec");
    $result = array();
    mt_srand($seed);
    for ($i = 0; $i < count($months); $i++) {
        $m      = $months[$i];
        $factor = 0.7 + mt_rand(0, 60) / 100;
        $sales  = round($base_sales * $factor / 1000) * 1000;
        $comm   = round($base_commission * $factor / 1000) * 1000;
        $deals  = max(1, (int)round($base_deals * $factor));
        $result[] = array("month" => $m, "month_num" => $i + 1, "sales" => $sales, "commission" => $comm, "deals" => $deals);
    }
    return $result;
}

// ─── YEARLY SUMMARY DATA ──────────────────────────────────────────────────
$yearly_data = array(
    2023 => array("sales" => 620000000,  "commission" => 19000000, "deals" => 290, "agents" => 95,  "avg_deal" => 2137931),
    2024 => array("sales" => 976453993,  "commission" => 28459408, "deals" => 511, "agents" => 103, "avg_deal" => 1911847),
    2025 => array("sales" => 819125547,  "commission" => 26778707, "deals" => 364, "agents" => 103, "avg_deal" => 2250345),
    2026 => array("sales" => 310000000,  "commission" => 9800000,  "deals" => 120, "agents" => 103, "avg_deal" => 2583333),
);

// ─── DEAL TYPE DISTRIBUTION BY YEAR ──────────────────────────────────────
$deal_dist = array(
    2023 => array(
        array("name" => "Offplan",     "value" => 38.2, "amount" => 236840000, "commission" => 7258200,  "deals" => 110),
        array("name" => "Secondary",   "value" => 34.5, "amount" => 213900000, "commission" => 6550000,  "deals" => 100),
        array("name" => "Rental",           "value" => 5.2,  "amount" => 32240000,  "commission" => 991800,   "deals" => 16),
    ),
    2024 => array(
        array("name" => "Offplan",     "value" => 34.1, "amount" => 332868429, "commission" => 10247862, "deals" => 214),
        array("name" => "Secondary",   "value" => 41.59, "amount" => 406155505, "commission" => 12485119, "deals" => 634),
        array("name" => "Rental",           "value" => 4.34, "amount" => 42530844,  "commission" => 1307875,  "deals" => 267),
    ),
    2025 => array(
        array("name" => "Offplan",     "value" => 40.63, "amount" => 332850072, "commission" => 10226552, "deals" => 161),
        array("name" => "Secondary",   "value" => 37.57, "amount" => 307757311, "commission" => 9454400,  "deals" => 214),
        array("name" => "Rental",           "value" => 3.68, "amount" => 30133000,  "commission" => 926236,   "deals" => 79),
    ),
    2026 => array(
        array("name" => "Offplan",     "value" => 42.0, "amount" => 130200000, "commission" => 4000000,  "deals" => 50),
        array("name" => "Secondary",   "value" => 35.5, "amount" => 110050000, "commission" => 3380000,  "deals" => 43),
        array("name" => "Rental",           "value" => 4.0,  "amount" => 12400000,  "commission" => 660000,   "deals" => 5),
    ),
);

// ─── TOP DEVELOPERS ───────────────────────────────────────────────────────
$top_developers = array(
    array("name" => "Emaar Properties PJSC", "amount" => 332850072, "commission" => 10226552, "deals" => 161),
    array("name" => "Meraas",                "amount" => 95430000,  "commission" => 2931900,  "deals" => 48),
    array("name" => "Meydan",                "amount" => 52760000,  "commission" => 1620600,  "deals" => 34),
    array("name" => "Dubai General",         "amount" => 38940000,  "commission" => 1196100,  "deals" => 26),
    array("name" => "DAMAC Properties",      "amount" => 29870000,  "commission" => 917150,   "deals" => 18),
    array("name" => "Sobha Realty",          "amount" => 21340000,  "commission" => 655500,   "deals" => 12),
    array("name" => "Wasl",                  "amount" => 15600000,  "commission" => 479100,   "deals" => 9),
);

// ─── TOP TYPES ───────────────────────────────────────────────────────
$top_property_types = array(
    array("name" => "Offplan",   "amount" => 332850072, "commission" => 10226552, "deals" => 161),
    array("name" => "Secondary", "amount" => 95430000,  "commission" => 2931900,  "deals" => 48),
    array("name" => "Rental",    "amount" => 52760000,  "commission" => 1620600,  "deals" => 34),
);

// ─── AGENT PERFORMANCE ────────────────────────────────────────────────────
$agent_performance = array(
    array("id" => 1,  "name" => "Ali Arikat",     "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 20, "sales" => 78026353,  "commission" => 2488233, "top_deal" => 13500000, "avg_gap" => 14, "last_deal_days" => 18, "attendance" => 43, "designation" => "Private Office Advisor"),
    array("id" => 2,  "name" => "Sara Mitchell",  "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 18, "sales" => 62140000,  "commission" => 1980000, "top_deal" => 9800000,  "avg_gap" => 17, "last_deal_days" => 5, "attendance" => 41,  "designation" => "Sales Associate"),
    array("id" => 3,  "name" => "Rami Hassan",    "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 24, "sales" => 91230000,  "commission" => 2970000, "top_deal" => 15200000, "avg_gap" => 12, "last_deal_days" => 3, "attendance" => 43,  "designation" => "Senior Consultant"),
    array("id" => 4,  "name" => "Nadia Al-Farsi", "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 15, "sales" => 48600000,  "commission" => 1550000, "top_deal" => 7300000,  "avg_gap" => 20, "last_deal_days" => 22, "attendance" => 23, "designation" => "Sales Associate"),
    array("id" => 5,  "name" => "James Thornton", "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 31, "sales" => 112500000, "commission" => 3580000, "top_deal" => 18900000, "avg_gap" => 9,  "last_deal_days" => 1, "attendance" => 43,  "designation" => "Senior Consultant"),
    array("id" => 6,  "name" => "Priya Sharma",   "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 11, "sales" => 35200000,  "commission" => 1100000, "top_deal" => 5800000,  "avg_gap" => 25, "last_deal_days" => 41, "attendance" => 43, "designation" => "Sales Associate"),
    array("id" => 7,  "name" => "Omar Khalil",    "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 27, "sales" => 98700000,  "commission" => 3150000, "top_deal" => 16400000, "avg_gap" => 11, "last_deal_days" => 7, "attendance" => 15,  "designation" => "Senior Consultant"),
    array("id" => 8,  "name" => "Elena Petrova",  "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 22, "sales" => 84300000,  "commission" => 2690000, "top_deal" => 14100000, "avg_gap" => 13, "last_deal_days" => 12, "attendance" => 32, "designation" => "Private Office Advisor"),
    array("id" => 9,  "name" => "David Lee",      "leads" => 12, "reshuffled_leads" => 6, "listings" => 34, "deals" => 8,  "sales" => 24800000,  "commission" => 790000,  "top_deal" => 4200000,  "avg_gap" => 32, "last_deal_days" => 65, "attendance" => 52, "designation" => "Sales Associate"),
);

// ─── TEAM PERFORMANCE ────────────────────────────────────────────────────
$team_performance = array(
    array("id" => 1,  "name" => "Sales Team 1",     "deals" => 20, "leads" => 120, "listings" => 84, "sales" => 78026353,  "commission" => 2488233, "top_deal" => 13500000, "avg_gap" => 14, "last_deal_days" => 18,),
    array("id" => 2,  "name" => "Sales Team 2",     "deals" => 18, "leads" => 52, "listings" => 75, "sales" => 62140000,  "commission" => 1980000, "top_deal" => 9800000,  "avg_gap" => 17, "last_deal_days" => 5,),
    array("id" => 3,  "name" => "Sales Team 3",     "deals" => 24, "leads" => 32, "listings" => 14, "sales" => 91230000,  "commission" => 2970000, "top_deal" => 15200000, "avg_gap" => 12, "last_deal_days" => 3,),
    array("id" => 4,  "name" => "Sales Team 4",     "deals" => 15, "leads" => 12, "listings" => 45, "sales" => 48600000,  "commission" => 1550000, "top_deal" => 7300000,  "avg_gap" => 20, "last_deal_days" => 22,),
    array("id" => 5,  "name" => "Sales Team 5",     "deals" => 31, "leads" => 46, "listings" => 24, "sales" => 112500000, "commission" => 3580000, "top_deal" => 18900000, "avg_gap" => 9,  "last_deal_days" => 1,),
    array("id" => 6,  "name" => "Private Office",   "deals" => 11, "leads" => 15, "listings" => 74, "sales" => 35200000,  "commission" => 1100000, "top_deal" => 5800000,  "avg_gap" => 25, "last_deal_days" => 41,),
);

// ─── TARGET VS ACTUAL ─────────────────────────────────────────────────────
$target_vs_actual = array(
    array("month" => "Jan", "target" => 90000000, "actual" => 120102386),
    array("month" => "Feb", "target" => 90000000, "actual" => 83176675),
    array("month" => "Mar", "target" => 90000000, "actual" => 156524289),
    array("month" => "Apr", "target" => 90000000, "actual" => 175948504),
    array("month" => "May", "target" => 90000000, "actual" => 240702139),
    array("month" => "Jun", "target" => 90000000, "actual" => 186979482),
    array("month" => "Jul", "target" => 90000000, "actual" => 55822208),
    array("month" => "Aug", "target" => 90000000, "actual" => 130000000),
    array("month" => "Sep", "target" => 90000000, "actual" => 97000000),
    array("month" => "Oct", "target" => 90000000, "actual" => 145898049),
    array("month" => "Nov", "target" => 90000000, "actual" => 179912776),
    array("month" => "Dec", "target" => 90000000, "actual" => 82412200),
);

// ─── SALES BY DEAL TYPE ───────────────────────────────────────────────────
$sales_by_deal_type = array(
    "Offplan" => array(
        array("month" => "Jan", "sales" => 43875432, "commission" => 1549532, "deals" => 14),
        array("month" => "Feb", "sales" => 30104328, "commission" => 1204173, "deals" => 6),
        array("month" => "Mar", "sales" => 194261432, "commission" => 7039472, "deals" => 14),
        array("month" => "Apr", "sales" => 37543328, "commission" => 1378475, "deals" => 6),
        array("month" => "May", "sales" => 27065552, "commission" => 942774, "deals" => 4),
    ),
    "Secondary" => array(
        array("month" => "Jan", "sales" => 76285000, "commission" => 2086902, "deals" => 20),
        array("month" => "Feb", "sales" => 51475000, "commission" => 978590, "deals" => 18),
        array("month" => "Mar", "sales" => 44057311, "commission" => 1024811, "deals" => 17),
        array("month" => "Apr", "sales" => 1433000,  "commission" => 155000, "deals" => 15),
        array("month" => "May", "sales" => 43000000, "commission" => 1150000, "deals" => 22),
    ),
    "Rental" => array(
        array("month" => "Jan", "sales" => 8328000,  "commission" => 527207, "deals" => 18),
        array("month" => "Feb", "sales" => 9560000,  "commission" => 620000, "deals" => 22),
        array("month" => "Mar", "sales" => 7320000,  "commission" => 475000, "deals" => 17),
        array("month" => "Apr", "sales" => 5100000,  "commission" => 330000, "deals" => 12),
        array("month" => "May", "sales" => 6800000,  "commission" => 445000, "deals" => 16),
    ),
);

// ─── YEAR COMPARISON DATA ─────────────────────────────────────────────────
$year_bases = array(
    2023 => array("sales" => 52000000, "commission" => 1600000, "deals" => 24),
    2024 => array("sales" => 81000000, "commission" => 2370000, "deals" => 42),
    2025 => array("sales" => 68000000, "commission" => 2230000, "deals" => 30),
    2026 => array("sales" => 77000000, "commission" => 2450000, "deals" => 40),
);

$y1_base = isset($year_bases[$year1]) ? $year_bases[$year1] : $year_bases[2025];
$y2_base = isset($year_bases[$year2]) ? $year_bases[$year2] : $year_bases[2026];

$year1_monthly = buildMonthData($y1_base['sales'], $y1_base['commission'], $y1_base['deals'], $year1);
$year2_monthly = buildMonthData($y2_base['sales'], $y2_base['commission'], $y2_base['deals'], $year2 + 100);

$y1_summary = isset($yearly_data[$year1]) ? $yearly_data[$year1] : $yearly_data[2025];
$y2_summary = isset($yearly_data[$year2]) ? $yearly_data[$year2] : $yearly_data[2026];

// ─── SELECT YEAR DATA ─────────────────────────────────────────────────────
$sel_year  = is_numeric($year) ? (int)$year : 2025;
$curr_dist = isset($deal_dist[$sel_year]) ? $deal_dist[$sel_year] : $deal_dist[2025];
$curr_year = isset($yearly_data[$sel_year]) ? $yearly_data[$sel_year] : $yearly_data[2025];

// ─── BUILD RESPONSE ───────────────────────────────────────────────────────
$response = array(
    "role"    => $role,
    "filters" => $filters,
);

// ══════════════════════════════════════════════════════════════════════════
// AGENT VIEW
// ══════════════════════════════════════════════════════════════════════════
if ($role === 'agent') {
    $agent        = isset($agents[$agent_id]) ? $agents[$agent_id] : $agents[1];
    $manager_name = isset($agents[$agent['manager_id']]) ? $agents[$agent['manager_id']]['name'] : 'N/A';

    $response['view']  = 'agent';
    $response['agent'] = array(
        "profile" => array(
            "name"        => $agent['name'],
            "employee_no" => $agent['employee_no'],
            "designation" => $agent['designation'],
            "joined"      => $agent['joined'],
            "manager"     => $manager_name,
            "current"     => true,
        ),
        "summary" => array(
            "commissions"            => 2488233,
            "sales_volume"           => 78026353,
            "deal_count"             => 20,
            "avg_revenue"            => 124412,
            "avg_selling_price"      => 3901318,
            "avg_gap_days"           => 14,
            "top_deal"               => 13500000,
            "top_commission"         => 270000,
            "days_since_last"        => 18,
            "committed_commission"   => 180000,
            "operational_commission" => 2308233,
        ),
        "target_vs_actual" => array(
            array("month" => "Jan", "target" => 7000000, "actual" => 6200000),
            array("month" => "Feb", "target" => 7000000, "actual" => 8100000),
            array("month" => "Mar", "target" => 7000000, "actual" => 14300000),
            array("month" => "Apr", "target" => 7000000, "actual" => 5200000),
            array("month" => "May", "target" => 7000000, "actual" => 9800000),
            array("month" => "Jun", "target" => 7000000, "actual" => 7400000),
            array("month" => "Jul", "target" => 7000000, "actual" => 4100000),
        ),
        "deal_distribution" => $curr_dist,
        "top_developers" => array(
            array("name" => "Emaar Properties PJSC", "amount" => 45764178, "commission" => 1454567, "deals" => 11),
            array("name" => "Null",                 "amount" => 24592276, "commission" => 739543, "deals" => 4),
            array("name" => "Meydan",               "amount" => 3665011, "commission" => 183251, "deals" => 2),
            array("name" => "Dubai General",        "amount" => 3574888, "commission" => 89372,  "deals" => 1),
            array("name" => "Wasl",                 "amount" => 430000,  "commission" => 21500,  "deals" => 2),
        ),
        "top_property_types" => array(
            array("name" => "Offplan", "amount" => 45764178, "commission" => 1454567, "deals" => 11),
            array("name" => "Secondary",                 "amount" => 24592276, "commission" => 739543, "deals" => 4),
            array("name" => "Rental",               "amount" => 3665011, "commission" => 183251, "deals" => 2),
        ),
        "avg_ticket_size" => array(
            array("month" => "Jan", "value" => 1200000),
            array("month" => "Feb", "value" => 1800000),
            array("month" => "Mar", "value" => 3200000),
            array("month" => "Apr", "value" => 900000),
            array("month" => "May", "value" => 2100000),
            array("month" => "Jun", "value" => 1600000),
            array("month" => "Jul", "value" => 700000),
        ),
        "commission_trend" => array(
            array("month" => "Jan", "value" => 320000),
            array("month" => "Feb", "value" => 480000),
            array("month" => "Mar", "value" => 920000),
            array("month" => "Apr", "value" => 210000),
            array("month" => "May", "value" => 558000),
        ),
    );

    // ══════════════════════════════════════════════════════════════════════════
    // MANAGER VIEW
    // ══════════════════════════════════════════════════════════════════════════
} elseif ($role === 'manager') {
    $manager = isset($agents[$manager_id]) ? $agents[$manager_id] : $agents[10];

    $my_agents = array();
    foreach ($agent_performance as $ap) {
        if (isset($agents[$ap['id']]) && $agents[$ap['id']]['manager_id'] == $manager_id) {
            $my_agents[] = $ap;
        }
    }

    $total_leads      = 0;
    $total_deals      = 0;
    $total_listings   = 0;
    $total_sales      = 0;
    $total_commission = 0;
    $max_top_deal     = 0;
    foreach ($my_agents as $a) {
        $total_leads      += $a['leads'];
        $total_deals      += $a['deals'];
        $total_listings   += $a['listings'];
        $total_sales      += $a['sales'];
        $total_commission += $a['commission'];
        if ($a['top_deal'] > $max_top_deal) {
            $max_top_deal = $a['top_deal'];
        }
    }
    $committed   = (int)round($total_commission * 0.929);
    $operational = $total_commission - $committed;

    $response['view']    = 'manager';
    $response['manager'] = array(
        "profile" => array(
            "name"        => $manager['name'],
            "employee_no" => $manager['employee_no'],
            "designation" => $manager['designation'],
            "joined"      => $manager['joined'],
        ),
        "summary" => array(
            "active_agents"          => count($my_agents),
            "no_deal_60_days"        => 2,
            "deal_count"             => $total_deals,
            "lead_count"             => $total_leads,
            "listings_count"         => $total_listings,
            "sales_volume"           => $total_sales,
            "avg_sales_per_deal"     => $total_deals > 0 ? (int)round($total_sales / $total_deals) : 0,
            "avg_sales_per_month"    => (int)round($total_sales / 12),
            "top_deal"               => $max_top_deal,
            "commissions"            => $total_commission,
            "committed_commission"   => $committed,
            "operational_commission" => $operational,
            "avg_revenue_per_deal"   => 74000,
            "avg_revenue_per_month"  => 900000,
            "top_commission"         => 920000,
        ),
        "commission_trend" => array(
            array("month" => "Jan", "value" => 1800000),
            array("month" => "Feb", "value" => 2100000),
            array("month" => "Mar", "value" => 4200000),
            array("month" => "Apr", "value" => 1400000),
            array("month" => "May", "value" => 1900000),
        ),
        "target_vs_actual" => array(
            array("month" => "Jan", "target" => 25000000, "actual" => 28000000),
            array("month" => "Feb", "target" => 25000000, "actual" => 21000000),
            array("month" => "Mar", "target" => 25000000, "actual" => 52000000),
            array("month" => "Apr", "target" => 25000000, "actual" => 18000000),
            array("month" => "May", "target" => 25000000, "actual" => 31000000),
        ),
        "deal_distribution" => $curr_dist,
    );
    $response['all_agents'] = array_values($my_agents);

    // ══════════════════════════════════════════════════════════════════════════
    // CEO VIEW
    // ══════════════════════════════════════════════════════════════════════════
} else {
    $comm_committed   = (int)round($curr_year['commission'] * 0.929);
    $comm_operational = $curr_year['commission'] - $comm_committed;

    $response['view']    = 'ceo';
    $response['summary'] = array(
        "active_agents"              => $curr_year['agents'],
        "no_deal_60_days"            => 38,
        "deal_count"                 => $curr_year['deals'],
        "sales_volume"               => $curr_year['sales'],
        "avg_sales_per_deal"         => $curr_year['avg_deal'],
        "avg_sales_per_month"        => (int)round($curr_year['sales'] / 12),
        "top_deal"                   => 40370000,
        "commissions"                => $curr_year['commission'],
        "committed_commission"       => $comm_committed,
        "committed_commission_pct"   => 92.9,
        "operational_commission"     => $comm_operational,
        "operational_commission_pct" => 7.1,
        "avg_revenue_per_deal"       => 73568,
        "avg_revenue_per_month"      => 5355741,
        "active_listings_rent"       => 97,
        "active_listings_sale"       => 102,
        "top_commission"             => 1615000,
    );

    $response['commission_trend'] = array(
        array("month" => "Jan", "value" => 4220000),
        array("month" => "Feb", "value" => 4340000),
        array("month" => "Mar", "value" => 10690000),
        array("month" => "Apr", "value" => 3650000),
        array("month" => "May", "value" => 3880000),
    );

    $response['deal_distribution']  = $curr_dist;
    $response['top_developers']     = $top_developers;
    $response['top_property_types'] = $top_property_types;
    $response['target_vs_actual']   = $target_vs_actual;
    $response['sales_by_deal_type'] = $sales_by_deal_type;
    $response['agent_performance']  = $agent_performance;
    $response['team_performance']   = $team_performance;

    $response['year_comparison'] = array(
        "year1"         => $year1,
        "year2"         => $year2,
        "year1_monthly" => $year1_monthly,
        "year2_monthly" => $year2_monthly,
        "year1_summary" => $y1_summary,
        "year2_summary" => $y2_summary,
    );
}

echo json_encode($response);
